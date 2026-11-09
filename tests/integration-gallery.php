<?php
/** Disposable real WordPress tests for gallery, cross-format fingerprints and access checks. */
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_SOFTWARE']='Apache/2.4';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';
define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ACCESSIBLE_HOSTS','localhost');define('DISABLE_WP_CRON',true);
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/template.php';
wp_set_current_user(1);
function check_gallery($ok,$message){if(!$ok)throw new RuntimeException($message);echo "PASS: $message\n";}
update_option(Pixora::OPTION,array('automatic'=>0,'quality'=>82,'engine'=>'inplace','inplace_consent'=>0));
$uploads=wp_upload_dir();$prefix=$uploads['path'].'/pixora-gallery-'.uniqid();$ids=array();
$im=imagecreatetruecolor(128,96);
for($y=0;$y<96;++$y)for($x=0;$x<128;++$x)imagesetpixel($im,$x,$y,imagecolorallocate($im,($x*2)%256,($y*2)%256,($x+$y)%256));
imagefilledrectangle($im,24,30,68,55,imagecolorallocate($im,10,20,240));
imagejpeg($im,$prefix.'.jpg',95);imagedestroy($im);
$decoded=imagecreatefromjpeg($prefix.'.jpg');imagepng($decoded,$prefix.'.png');imagejpeg($decoded,$prefix.'-lossy.jpg',65);
copy($prefix.'.jpg',$prefix.'.jpeg');
$files=array($prefix.'.jpg',$prefix.'.png',$prefix.'.jpeg',$prefix.'-lossy.jpg');
if(defined('IMG_WEBP_LOSSLESS')){imagewebp($decoded,$prefix.'.webp',IMG_WEBP_LOSSLESS);$files[]=$prefix.'.webp';}
imagedestroy($decoded);
$other=imagecreatetruecolor(128,96);imagefill($other,0,0,imagecolorallocate($other,240,5,5));imagepng($other,$prefix.'-other.png');imagedestroy($other);$files[]=$prefix.'-other.png';
foreach($files as $path){$id=wp_insert_attachment(array('post_title'=>'Gallery <test>','post_status'=>'inherit','post_mime_type'=>wp_get_image_mime($path)),$path);wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$path));$ids[]=$id;}
$data=array();foreach($ids as $id){$data[]=Pixora_Gallery::fingerprint($id);}
check_gallery(Pixora_Gallery::classify($data[0],$data[2])==='file','Identical bytes are detected across jpg/jpeg filenames');
check_gallery(Pixora_Gallery::classify($data[0],$data[1])==='pixels','Decoded JPEG and PNG pixels match despite different file formats');
check_gallery(Pixora_Gallery::classify($data[0],$data[3])==='similar','Lossy re-encoding is labelled probable similarity, not exact duplication');
check_gallery(Pixora_Gallery::classify($data[0],end($data))==='','Different colored image is not grouped as a duplicate');
if(defined('IMG_WEBP_LOSSLESS'))check_gallery(Pixora_Gallery::classify($data[1],$data[4])==='pixels','Lossless WebP and PNG match by decoded pixels');
check_gallery(Pixora_Gallery::fingerprint($ids[0])===$data[0],'Unchanged file uses stable cached fingerprint');
$_GET=array('page'=>'pixora-gallery');ob_start();Pixora_Gallery::page();$page=ob_get_clean();
check_gallery(strpos($page,'pixora-gallery-grid')!==false&&strpos($page,'گالری Pixora')!==false,'Real gallery renders image cards and controls');
check_gallery(strpos($page,'<strong>مشابه احتمالی')!==false,'Global similarity candidates produce review groups');
check_gallery(strpos($page,'پیکسل یکسان')!==false&&strpos($page,'فایل کاملاً یکسان')!==false,'Global exact-duplicate groups render from real database queries');
if(getenv('PIXORA_GALLERY_EXPORT')){
 $render=preg_replace('/\s(?:srcset|sizes)="[^"]*"/','',$page);
 $render=preg_replace_callback('/src="([^"]+)"/',function($m){$path=ABSPATH.ltrim((string)wp_parse_url(html_entity_decode($m[1]),PHP_URL_PATH),'/');return Pixora::safe_file($path)?'src="data:'.wp_get_image_mime($path).';base64,'.base64_encode(file_get_contents($path)).'"':$m[0];},$render);
 $css=file_get_contents(ABSPATH.'wp-includes/css/buttons.min.css').file_get_contents(ABSPATH.'wp-admin/css/common.min.css').file_get_contents(WP_PLUGIN_DIR.'/pixora/assets/admin.css');
 file_put_contents(getenv('PIXORA_GALLERY_EXPORT'),'<!doctype html><html lang="fa" dir="rtl"><meta charset="utf-8"><style>'.$css.'</style><body>'.$render.'</body></html>');
}
check_gallery(strpos($page,'Gallery &lt;test&gt;')!==false&&strpos($page,'<test>')===false,'Attachment titles are escaped in gallery');
// Cache invalidation is mandatory after source replacement or WordPress metadata updates.
$metadata=wp_get_attachment_metadata($ids[0]);$metadata['pixora_test']=true;wp_update_attachment_metadata($ids[0],$metadata);
check_gallery(!get_post_meta($ids[0],Pixora_Gallery::META,true)&&!get_post_meta($ids[0],'_pixora_sha',true),'Metadata update invalidates duplicate index');
Pixora_Gallery::fingerprint($ids[0]);
if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
class Gallery_Json_End extends RuntimeException{}
add_filter('wp_die_ajax_handler',function(){return function($message=''){if((string)$message==='-1')echo '-1';throw new Gallery_Json_End();};});
function scan_ajax($cursor,$user=1,$valid=true){wp_set_current_user($user);$_POST=array('cursor'=>$cursor);$_REQUEST=array('nonce'=>$valid?wp_create_nonce('pixora_batch'):'bad');ob_start();try{Pixora_Gallery::scan();}catch(Gallery_Json_End $end){}$raw=ob_get_clean();return json_decode($raw,true)??$raw;}
$denied=wp_insert_user(array('user_login'=>'gallery-viewer-'.uniqid(),'user_pass'=>wp_generate_password(32),'role'=>'subscriber'));
check_gallery(!scan_ajax(0,$denied)['success'],'Scan endpoint denies a subscriber');
check_gallery(scan_ajax(0,1,false)===-1,'Scan endpoint rejects an invalid nonce');
$scan=scan_ajax($ids[0]-1);check_gallery($scan['success']&&$scan['data']['cursor']===$ids[0],'Scan advances one attachment per AJAX request');
wp_set_current_user($denied);ob_start();Pixora_Gallery::page();$denied_page=ob_get_clean();check_gallery($denied_page==='','Gallery is hidden from unauthorized users');
wp_set_current_user(1);foreach($ids as $id)wp_delete_attachment($id,true);
echo 'Real gallery tests complete; WordPress '.get_bloginfo('version')."\n";
