/* Real WordPress + bundled Plupload + Chromium, through a CLI-only bridge to the disposable container. */
const { chromium } = require('playwright');
const { spawn } = require('node:child_process');
const readline = require('node:readline');
const fs = require('node:fs');
const assert = require('node:assert/strict');
const auth = process.argv[2];
if (!auth) throw new Error('Pass disposable browser session JSON');
const bridge=spawn('docker',['compose','-p','pixora-browser','-f','tests/compose.yaml','exec','-T','wordpress','php','/opt/pixora-tests/browser-proxy.php'],{stdio:['pipe','pipe','inherit']});
const waiting=new Map();let requestId=0;
readline.createInterface({input:bridge.stdout}).on('line',line=>{
 const data=JSON.parse(line), job=waiting.get(data.id);if(!job)return;waiting.delete(data.id);
 if(data.error)job.reject(new Error(data.error));else job.resolve({status:data.status,headers:data.headers,body:Buffer.from(data.body,'base64')});
});
bridge.on('exit',()=>{for(const job of waiting.values())job.reject(new Error('Test bridge closed'));waiting.clear();});
function forward(request) {
 return new Promise((resolve,reject)=>{
  const url=new URL(request.url()),id=++requestId;
  const headers={...request.headers(),host:'localhost'},body=request.postDataBuffer();
  waiting.set(id,{resolve,reject});bridge.stdin.write(JSON.stringify({id,path:url.pathname+url.search,method:request.method(),headers,body:body?body.toString('base64'):null})+'\n');
 });
}
(async()=>{
 const browser=await chromium.launch({executablePath:process.env.PIXORA_CHROMIUM||'/usr/bin/chromium',args:['--no-sandbox'],headless:true});
 const uploaded=[];let workers=0;
 try{
  const context=await browser.newContext();await context.addCookies(JSON.parse(fs.readFileSync(auth,'utf8')).cookies);
  await context.route('http://localhost/**',async route=>{
   const req=route.request();
   if(req.method()==='POST'&&req.url().includes('async-upload.php')&&req.headers()['content-type']?.includes('multipart/form-data')){
    const body=req.postDataBuffer();
    assert.match(body.toString('latin1'),/filename="[^"]+\.webp"/,'Only a WebP filename crosses the network');
    assert.match(body.toString('latin1'),/Content-Type: image\/webp/i);
    assert.ok(body.includes(Buffer.from('RIFF'))&&body.includes(Buffer.from('WEBP')),'Multipart carries actual WebP bytes');
    assert.equal(body.includes(Buffer.from('Content-Type: image/jpeg')),false);
    assert.equal(body.includes(Buffer.from('Content-Type: image/png')),false);
    const response=await forward(req);
    const result=JSON.parse(response.body.toString());
    if(result.success)uploaded.push(result.data);else if(typeof result==='number')uploaded.push({id:result,classic:true});
    await route.fulfill(response);return;
   }
   await route.fulfill(await forward(req));
  });
  const page=await context.newPage();page.on('worker',()=>++workers);
  const errors=[];page.on('pageerror',error=>errors.push(error.message));
  await page.goto('http://localhost/wp-admin/upload.php?mode=grid');
  await page.waitForFunction(()=>window.pixoraBrowser&&window.wp&&wp.media&&window.PixoraCodec);
  // Open the real WordPress media dialog and select its native multi-file input.
  await page.evaluate(()=>{window.testFrame=wp.media({title:'Pixora browser integration',multiple:true});testFrame.open();testFrame.setState('upload');});
  const fixtures=await page.evaluate(async()=>{
   const canvas=document.createElement('canvas');canvas.width=1200;canvas.height=800;const ctx=canvas.getContext('2d');
   for(let y=0;y<800;y+=8)for(let x=0;x<1200;x+=8){ctx.fillStyle=`rgb(${x%256},${y%256},${(x+y)%256})`;ctx.fillRect(x,y,8,8);}
   const jpg=await new Promise(r=>canvas.toBlob(r,'image/jpeg',0.99));
   ctx.clearRect(0,0,1200,800);ctx.fillStyle='rgba(20,80,200,0.4)';ctx.fillRect(0,0,1200,800);
   const png=await new Promise(r=>canvas.toBlob(r,'image/png'));
   return {jpg:Array.from(new Uint8Array(await jpg.arrayBuffer())),png:Array.from(new Uint8Array(await png.arrayBuffer()))};
  });
  const stamp=Date.now();
  await page.locator('input[type=file]').last().setInputFiles([
   {name:`pixora-browser-${stamp}.jpg`,mimeType:'image/jpeg',buffer:Buffer.from(fixtures.jpg)},
   {name:`pixora-browser-alpha-${stamp}.png`,mimeType:'image/png',buffer:Buffer.from(fixtures.png)}
  ]);
  await page.waitForFunction(()=>wp.Uploader.queue.length===0,{},{timeout:60000});
  await page.waitForFunction(()=>document.querySelector('.pixora-browser-status')?.textContent.includes('آمادهٔ ارسال'),{},{timeout:60000});
  for(let i=0;i<200&&uploaded.length<2;++i)await new Promise(r=>setTimeout(r,100));
  assert.equal(uploaded.length,2,'Both real media uploads must complete');
  assert.ok(workers>=2,'Encoding uses dedicated browser workers');
  for(const media of uploaded){assert.equal(media.mime,'image/webp');assert.match(media.url,/\.webp$/);assert.equal(media.width,1200);assert.equal(media.height,800);}
  const local=await page.evaluate(async fixtures=>{
   const make=(bytes,name,type)=>new File([new Uint8Array(bytes)],name,{type});
   const file=make(fixtures.jpg,'test.jpg','image/jpeg');
   const low=await PixoraCodec.convert(file,35),high=await PixoraCodec.convert(file,95);
   const png=await PixoraCodec.convert(make(fixtures.png,'test.png','image/png'),82);
   const bitmap=await createImageBitmap(png.blob);const c=new OffscreenCanvas(1,1);const cx=c.getContext('2d');cx.drawImage(bitmap,0,0);const alpha=cx.getImageData(0,0,1,1).data[3];bitmap.close();
   const bad=[];
   for(const f of [make([1,2,3],'bad.jpg','image/jpeg'),new File([new Uint8Array(65*1048576)],'huge.jpg',{type:'image/jpeg'})]){
    try{await PixoraCodec.convert(f);bad.push(false);}catch{bad.push(true);}
   }
   const exif=new Uint8Array([255,225,0,34,69,120,105,102,0,0,73,73,42,0,8,0,0,0,1,0,18,1,3,0,1,0,0,0,6,0,0,0,0,0,0,0]);
   const jpgBytes=new Uint8Array(fixtures.jpg);
   const rotated=await PixoraCodec.convert(new File([jpgBytes.slice(0,2),exif,jpgBytes.slice(2)],'rotated.jpg',{type:'image/jpeg'}));
   const pngBytes=new Uint8Array(fixtures.png),animation=new Uint8Array([0,0,0,8,97,99,84,76,0,0,0,1,0,0,0,0,0,0,0,0]);
   let apngRejected=false;
   try{await PixoraCodec.convert(new File([pngBytes.slice(0,33),animation,pngBytes.slice(33)],'animated.png',{type:'image/png'}));}catch{apngRejected=true;}
   return {low:low.after,high:high.after,alpha,bad,rotated:[rotated.width,rotated.height],apngRejected};
  },fixtures);
  assert.ok(local.low<local.high,'Quality changes encoded output size');assert.ok(local.alpha>0&&local.alpha<255,'PNG alpha survives browser encoding');assert.deepEqual(local.bad,[true,true]);assert.deepEqual(local.rotated,[800,1200],'EXIF orientation is applied once');assert.equal(local.apngRejected,true);
  // Invalid JPEG must produce a visible error and send no request.
  await page.locator('input[type=file]').last().setInputFiles({name:'pixora-invalid.jpg',mimeType:'image/jpeg',buffer:Buffer.from('invalid JPEG')});
  await page.locator('.pixora-browser-error').waitFor();assert.equal(uploaded.length,2);
  await page.evaluate(()=>{window.Worker=undefined;});
  await page.locator('input[type=file]').last().setInputFiles({name:`pixora-browser-fallback-${stamp}.jpg`,mimeType:'image/jpeg',buffer:Buffer.from(fixtures.jpg)});
  for(let i=0;i<200&&uploaded.length<3;++i)await new Promise(r=>setTimeout(r,100));
  assert.equal(uploaded.length,3,'Local page encoder works when workers are unavailable');
  await page.goto('http://localhost/wp-admin/media-new.php');
  await page.waitForFunction(()=>window.pixoraBrowser&&window.uploader);
  const limitTest=await page.evaluate(async bytes=>{
   const file=new File([new Uint8Array(bytes)],'limit.jpg',{type:'image/jpeg'}),encoded=await PixoraCodec.convert(file,82);
   const limit=Math.floor((file.size+encoded.after)/2);
   uploader.setOption('filters',{...uploader.getOption('filters'),pixora_webp:{max_file_size:limit}});
   return {before:file.size,after:encoded.after,limit};
  },fixtures.jpg);
  assert.ok(limitTest.before>limitTest.limit&&limitTest.after<limitTest.limit,'Upload limit test uses a larger local source and a smaller encoded payload');
  await page.locator('.moxie-shim input[type=file]').last().setInputFiles({name:`pixora-browser-classic-${stamp}.jpg`,mimeType:'image/jpeg',buffer:Buffer.from(fixtures.jpg)});
  for(let i=0;i<200&&uploaded.length<4;++i)await new Promise(r=>setTimeout(r,100));

  assert.equal(uploaded.length,4,'Standalone Add Media screen also sends only WebP');
  await page.evaluate(()=>uploader.setOption('filters',{...uploader.getOption('filters'),pixora_webp:{max_file_size:1}}));
  await page.locator('.moxie-shim input[type=file]').last().setInputFiles({name:'pixora-over-limit.jpg',mimeType:'image/jpeg',buffer:Buffer.from(fixtures.jpg)});
  await page.locator('.pixora-browser-error').waitFor();assert.equal(uploaded.length,4,'Encoded output exceeding the host limit is not sent');
  await page.screenshot({path:'/tmp/pixora-browser-preview.png',fullPage:true});
  assert.equal(errors.length,0,errors.join('\n'));
  console.log('PASS: native WordPress media dialog converts two queued files in workers and local-page fallback; multipart contains only real WebP; real uploads succeed; dimensions, EXIF orientation, quality and transparency preserved; animation and invalid/oversized inputs rejected; classic uploader and encoded host limits verified');
  // Store IDs only for disposal verification, never cookies.
  fs.writeFileSync('/tmp/pixora-browser-uploaded.json',JSON.stringify(uploaded.map(x=>x.id)));
 }finally{await browser.close();bridge.stdin.end();}
})().catch(error=>{console.error(error);process.exit(1);});
