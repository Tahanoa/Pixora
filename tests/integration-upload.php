<?php
/** Disposable WordPress fixtures: actual sideload upload path and generated metadata. */
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_SOFTWARE']='Apache/2.4';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';
define('DISABLE_WP_CRON',true);
require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/file.php';require_once ABSPATH.'wp-admin/includes/media.php';
wp_set_current_user(1);
function upload_check($ok,$text){if(!$ok)throw new RuntimeException($text);echo "PASS: $text\n";}
function upload_fixture($name,$png=false){
 $tmp=wp_tempnam($name);$im=imagecreatetruecolor(900,650);
 if($png){imagealphablending($im,false);imagesavealpha($im,true);imagefill($im,0,0,imagecolorallocatealpha($im,25,90,180,70));imagepng($im,$tmp);}
 else{for($y=0;$y<650;$y+=10)for($x=0;$x<900;$x+=10)imagefilledrectangle($im,$x,$y,$x+9,$y+9,imagecolorallocate($im,$x%256,$y%256,($x+$y)%256));imagejpeg($im,$tmp,98);}
 imagedestroy($im);return array('name'=>$name,'tmp_name'=>$tmp,'error'=>0,'size'=>filesize($tmp));
}
$old=get_option(Pixora::OPTION,array());$ids=array();$extra=array();
try{
 update_option(Pixora::OPTION,array('upload_webp_only'=>1,'quality'=>82,'automatic'=>0));
 foreach(array(false,true)as$png){
  $name='pixora-upload-'.uniqid().($png?'.png':'.jpg');$input=upload_fixture($name,$png);
  $id=media_handle_sideload($input,0);upload_check(!is_wp_error($id),'Actual WordPress media upload succeeds');$ids[]=$id;
  $file=get_attached_file($id);$meta=wp_get_attachment_metadata($id);
  upload_check(get_post_mime_type($id)==='image/webp'&&wp_get_image_mime($file)==='image/webp','Media MIME and stored bytes are WebP from the start');
  upload_check(!file_exists(dirname($file).'/'.$name)&&!get_post_meta($id,Pixora::BACKUP,true),'No original JPEG/PNG or Pixora backup remains');
  upload_check(substr(wp_get_attachment_url($id),-5)==='.webp'&&strpos(wp_get_attachment_image_srcset($id),'webp')!==false,'Public URL and srcset use WebP without rewrites');
  $response=wp_remote_get(str_replace('http://localhost','http://static',wp_get_attachment_url($id)));
  upload_check(!is_wp_error($response)&&wp_remote_retrieve_response_code($response)===200&&hash('sha256',wp_remote_retrieve_body($response))===hash_file('sha256',$file),'New WebP URL serves exact bytes through static Nginx without redirects');
  foreach($meta['sizes']as$size)upload_check($size['mime-type']==='image/webp'&&wp_get_image_mime(dirname($file).'/'.$size['file'])==='image/webp','WordPress thumbnail is also WebP');
  if($png){$im=imagecreatefromwebp($file);upload_check(((imagecolorat($im,0,0)>>24)&127)>0,'Transparent PNG retains transparency');imagedestroy($im);}
 }
 $u=wp_upload_dir();$stem='pixora-collision-'.uniqid();$collision=$u['path'].'/'.$stem.'.webp';file_put_contents($collision,'existing-file-do-not-overwrite');$extra[]=$collision;
 $id=media_handle_sideload(upload_fixture($stem.'.jpg'),0);upload_check(!is_wp_error($id),'Colliding WebP filename does not fail upload');$ids[]=$id;
 upload_check(file_get_contents($collision)==='existing-file-do-not-overwrite'&&get_attached_file($id)!==$collision,'Existing WebP is never overwritten');
 $disable=function(){return array();};add_filter('wp_image_editors',$disable);
 $name='pixora-rejected-'.uniqid().'.jpg';$rejected=media_handle_sideload(upload_fixture($name),0);remove_filter('wp_image_editors',$disable);
 upload_check(is_wp_error($rejected)&&!file_exists($u['path'].'/'.$name),'Unsupported WebP returns an upload error without leaving a JPEG');
 $name='pixora-denied-'.uniqid().'.jpg';
 $deny=function($file)use($name){return basename($file)===$name?'':$file;};add_filter('wp_delete_file',$deny);
 $denied=media_handle_sideload(upload_fixture($name),0);remove_filter('wp_delete_file',$deny);
 upload_check(is_wp_error($denied)&&!file_exists($u['path'].'/'.pathinfo($name,PATHINFO_FILENAME).'.webp'),'Failed original deletion rejects upload and rolls back WebP');
 if(file_exists($u['path'].'/'.$name))$extra[]=$u['path'].'/'.$name;
 update_option(Pixora::OPTION,array('upload_webp_only'=>0,'automatic'=>0));
 $name='pixora-disabled-'.uniqid().'.jpg';$id=media_handle_sideload(upload_fixture($name),0);$ids[]=$id;
 upload_check(!is_wp_error($id)&&get_post_mime_type($id)==='image/jpeg','Explicitly disabling upload-only mode preserves normal WordPress behavior');
 echo "WebP-only upload integration complete\n";
}finally{foreach($ids as$id)if(!is_wp_error($id))wp_delete_attachment($id,true);foreach($extra as$f)if(file_exists($f))unlink($f);update_option(Pixora::OPTION,$old);}
