const fs=require('node:fs');const vm=require('node:vm');const assert=require('node:assert/strict');
const source=fs.readFileSync(__dirname+'/../pixora/assets/admin.js','utf8');
function app(mode){
 const node=dataset=>({dataset,disabled:false,textContent:'',listeners:{},addEventListener(type,fn){this.listeners[type]=fn;}});
 const buttons=Object.fromEntries(['optimize','webp_delete','purge_webp','preview_webp_cleanup'].map(m=>[m,node({pixora:m})]));
 const elements={'pixora-selection':{value:'selected-test-token',dataset:{mode}},'pixora-stop':node({}),'pixora-reset':node({}),'pixora-progress':node({}),'pixora-log':node({}),'pixora-cleanup-consent':{checked:false},'pixora-webp-consent':{checked:false}};
 const store=new Map();const payload=[];const queue=[];
 const context={URLSearchParams,console,pixora:{url:'/ajax',nonce:'test',modePurge:'purge',modePreview:'preview',modeStrict:'strict',modeOptimize:'optimize',freed:'مگابایت آزادشده',saved:'کاهش حجم',consent:'تأیید لازم است',finished:'done'},document:{addEventListener(){},querySelectorAll(selector){return selector==='[data-pixora]'?Object.values(buttons):[];},getElementById(id){return elements[id];}},sessionStorage:{getItem:k=>store.get(k),setItem:(k,v)=>store.set(k,v),removeItem:k=>store.delete(k)},fetch:async(url,args)=>{payload.push(Object.fromEntries(args.body));assert.ok(queue.length);const data=queue.shift();return{ok:true,status:200,text:async()=>JSON.stringify({success:true,data})};}};
 vm.createContext(context);vm.runInContext(source,context);return{buttons,elements,store,payload,queue};
}
(async()=>{
 const a=app('purge_webp');assert.equal(a.buttons.optimize.disabled,true);assert.equal(a.buttons.webp_delete.disabled,true);assert.equal(a.buttons.preview_webp_cleanup.disabled,false);
 a.queue.push({cursor:7,status:'processed',saved:0,freed:0,potential:3000,deleted:0,message:'preview'}, {done:true});
 await a.buttons.preview_webp_cleanup.listeners.click();
 assert.equal(a.payload[0].selection,'selected-test-token');assert.equal(a.payload[0].consent,'');assert.match(a.elements['pixora-progress'].textContent,/هنوز حذف نشده/);
 const requests=a.payload.length;await a.buttons.purge_webp.listeners.click();assert.equal(a.payload.length,requests);
 a.elements['pixora-webp-consent'].checked=true;
 a.queue.push({cursor:7,status:'processed',saved:0,freed:3000,deleted:2,message:'deleted'}, {done:true});
 await a.buttons.purge_webp.listeners.click();assert.equal(a.payload.at(-1).consent,'delete-pixora-webp');assert.equal(a.payload.at(-1).selection,'selected-test-token');assert.match(a.elements['pixora-progress'].textContent,/کپی حذف‌شده: ۲/);assert.equal(a.buttons.optimize.disabled,true);
 const b=app('webp_delete');await b.buttons.webp_delete.listeners.click();assert.equal(b.payload.length,0);b.elements['pixora-cleanup-consent'].checked=true;b.queue.push({done:true});await b.buttons.webp_delete.listeners.click();assert.equal(b.payload[0].consent,'delete-originals');assert.equal(b.payload[0].mode,'webp_delete');
 console.log('PASS: selected scope, preview without deletion claims, separate purge consent, copy counts and strict-action consent');
})().catch(error=>{console.error(error);process.exitCode=1;});
