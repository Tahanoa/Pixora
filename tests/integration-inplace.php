<?php
/** Real WordPress with Nginx static delivery that ignores .htaccess. Disposable test install only. */
$_SERVER['HTTP_HOST'] = 'localhost'; $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$_SERVER['REQUEST_URI'] = '/'; $_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_HTTP_BLOCK_EXTERNAL', true); define('WP_ACCESSIBLE_HOSTS', 'localhost,static'); define('DISABLE_WP_CRON', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/template.php';
wp_set_current_user(1);
function expect_disk($condition, $message) { if (!$condition) throw new RuntimeException($message); echo "PASS: $message\n"; }
function fixture_disk($format) {
 $uploads = wp_upload_dir(); $path = $uploads['path'] . '/pixora-disk-' . uniqid() . '.' . $format;
 $im = imagecreatetruecolor(720, 480); imagealphablending($im, false); imagesavealpha($im, true);
 for ($y=0; $y<480; $y+=4) for ($x=0; $x<720; $x+=4) imagefilledrectangle($im, $x, $y, $x+3, $y+3, imagecolorallocatealpha($im, ($x*3)%256, ($y*5)%256, ($x+$y)%256, $format === 'png' ? ($x+$y)%128 : 0));
 if ($format === 'png') imagepng($im, $path, 0); else imagejpeg($im, $path, 100);
 imagedestroy($im);
 $id = wp_insert_attachment(array('post_title'=>'Disk test', 'post_status'=>'inherit', 'post_mime_type'=>$format === 'png' ? 'image/png' : 'image/jpeg'), $path);
 wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $path));
 return array($id, $path);
}
function disk_paths($id) {
 $file=get_attached_file($id); $meta=wp_get_attachment_metadata($id); $paths=array($file);
 foreach ($meta['sizes'] ?? array() as $size) $paths[]=dirname($file).'/'.$size['file'];
 return array_unique($paths);
}
function disk_bytes($paths) { $size=0; foreach($paths as $p) { clearstatcache(true,$p); $size+=filesize($p); } return $size; }
function pixel_digest($path) {
 $im=imagecreatefrompng($path); $context=hash_init('sha256');
 for($y=0;$y<imagesy($im);++$y) for($x=0;$x<imagesx($im);++$x) hash_update($context,pack('N',imagecolorat($im,$x,$y)));
 imagedestroy($im); return hash_final($context);
}
update_option(Pixora::OPTION, array('automatic'=>0,'quality'=>72,'engine'=>'inplace','inplace_consent'=>1));
$upload_filter=function($u) { $old=$u['baseurl']; $u['baseurl']='http://static/wp-content/uploads'; $u['url']=str_replace($old,$u['baseurl'],$u['url']); return $u; };
add_filter('upload_dir',$upload_filter);
$ids=array(); $freed_total=0;
foreach(array('jpg','png') as $format) {
 list($id,$path)=fixture_disk($format); $ids[]=$id;
 $url=wp_get_attachment_url($id); $paths=disk_paths($id); $before=disk_bytes($paths); $metadata=wp_get_attachment_metadata($id);
 $pixels=$format==='png'?pixel_digest($path):null;
 $rules=dirname($path).'/.htaccess';
 $rules_before=is_file($rules)?file_get_contents($rules):null;
 $preflight=Pixora_Storage::preflight($path);
 expect_disk(is_wp_error($preflight),'Reproduced: Nginx frontend ignores Apache image rules for '.$format);
 $block=function() { return new WP_Error('network_disabled','All outbound HTTP blocked for in-place test'); };
 add_filter('pre_http_request',$block);
 $force_format=function($formats){return array('image/jpeg'=>'image/webp','image/png'=>'image/webp');};
 add_filter('image_editor_output_format',$force_format,9999);
 $result=Pixora_Diagnostics::run($id,'inplace');
 remove_filter('image_editor_output_format',$force_format,9999);
 remove_filter('pre_http_request',$block);
 $after=disk_bytes($paths);
 expect_disk(!is_wp_error($result) && $result['freed']>0 && $after<$before,'Same-format optimization really reduces disk usage for '.$format);
 expect_disk($before-$after===$result['freed'],'Reported bytes match actual filesystem reduction for '.$format);
 expect_disk(get_attached_file($id)===$path && wp_get_attachment_url($id)===$url,'File path and public URL remain identical for '.$format);
 expect_disk(!get_post_meta($id,Pixora::BACKUP,true),'No WebP copy or restoration backup is created for '.$format);
 $response=wp_remote_get($url);
 expect_disk(!is_wp_error($response) && wp_remote_retrieve_response_code($response)===200 && wp_remote_retrieve_header($response,'content-type')===($format==='png'?'image/png':'image/jpeg'),'Original URL works through Nginx without rewrites for '.$format.' ('.(is_wp_error($response)?$response->get_error_message():wp_remote_retrieve_response_code($response).' '.wp_remote_retrieve_header($response,'content-type')).'; '.$url.')');
 expect_disk(hash('sha256',wp_remote_retrieve_body($response))===hash_file('sha256',$path),'Nginx returns actual optimized bytes for '.$format);
 expect_disk(!glob(dirname($path).'/.pixora-*.jpg') && !glob(dirname($path).'/.pixora-*.png'),'No temporary image copies remain for '.$format);
 if($format==='png') expect_disk(pixel_digest($path)===$pixels,'Transparent PNG pixels and alpha are preserved in this fixture');
 $hash=hash_file('sha256',$path); $second=Pixora_Diagnostics::run($id,'inplace');
 expect_disk($second['freed']===0 && hash_file('sha256',$path)===$hash,'Repeated click does not recompress and degrade '.$format);
 expect_disk(Pixora_Inplace::total(get_post_meta($id,Pixora_Inplace::META,true))===$result['freed'],'Persistent disk accounting is accurate for '.$format);
 $freed_total+=$result['freed'];
 echo 'MEASURED '.$format.': '.$before.' -> '.$after.' bytes (freed '.$result['freed'].")\n";
}
// Verify actual AJAX capability and consent enforcement for irreversible replacement.
if (!defined('DOING_AJAX')) define('DOING_AJAX',true);
class Disk_Ajax_End extends RuntimeException {}
add_filter('wp_die_ajax_handler',function(){return function($message=''){if((string)$message==='-1')echo '-1';throw new Disk_Ajax_End();};});
function disk_ajax($payload,$user=1,$valid=true){
 wp_set_current_user($user); $_POST=$payload; $_REQUEST=$payload; $_REQUEST['nonce']=$valid?wp_create_nonce('pixora_batch'):'bad';
 ob_start();try{Pixora::single();}catch(Disk_Ajax_End $end){}$raw=ob_get_clean();return json_decode($raw,true)??$raw;
}
$author=wp_insert_user(array('user_login'=>'disk-author-'.uniqid(),'user_pass'=>wp_generate_password(32),'role'=>'author'));
wp_update_post(array('ID'=>$ids[0],'post_author'=>$author));
expect_disk(!disk_ajax(array('id'=>$ids[0],'mode'=>'inplace','consent'=>'delete-originals'),$author)['success'],'Non-admin author cannot overwrite media originals');
expect_disk(!disk_ajax(array('id'=>$ids[0],'mode'=>'inplace'))['success'],'AJAX replacement requires explicit backup consent');
expect_disk(disk_ajax(array('id'=>$ids[0],'mode'=>'inplace','consent'=>'delete-originals'),1,false)===-1,'AJAX replacement rejects invalid nonce');
wp_set_current_user(1);
// Existing WebP files are kept: content may already reference them.
remove_filter('upload_dir',$upload_filter);
list($legacy,$original)=fixture_disk('jpg');$ids[]=$legacy;
$converted=Pixora_Diagnostics::run($legacy,'optimize');
expect_disk(!is_wp_error($converted),'Existing WebP fixture created');
$webp=get_attached_file($legacy);$webp_hash=hash_file('sha256',$webp);
add_filter('upload_dir',$upload_filter);
$result=Pixora_Diagnostics::run($legacy,'inplace');
expect_disk(!is_wp_error($result) && $result['freed']>0 && file_exists($original),'Existing retained JPEG originals can also be reduced');
expect_disk(is_file($webp) && hash_file('sha256',$webp)===$webp_hash,'Previously published WebP URLs remain intact');
// Persistence failure must never overwrite the only original.
list($db_id,$db_file)=fixture_disk('jpg');$ids[]=$db_id;$db_hash=hash_file('sha256',$db_file);
$deny_state=function($check,$object_id,$meta_key) { return $meta_key===Pixora_Inplace::META ? false : $check; };
add_filter('update_post_metadata',$deny_state,10,3);
$denied=Pixora_Diagnostics::run($db_id,'inplace');
remove_filter('update_post_metadata',$deny_state,10);
expect_disk($denied['freed']===0 && hash_file('sha256',$db_file)===$db_hash,'Metadata persistence failure preserves original bytes');
list($ro_id,$ro_file)=fixture_disk('jpg');$ids[]=$ro_id;$ro_hash=hash_file('sha256',$ro_file);
foreach(disk_paths($ro_id) as $ro) chmod($ro,0444);
$readonly=Pixora_Diagnostics::run($ro_id,'inplace');
expect_disk($readonly['freed']===0 && hash_file('sha256',$ro_file)===$ro_hash,'Read-only images are preserved without replacements');
foreach(disk_paths($ro_id) as $ro) chmod($ro,0644);
// Automatic upload must use the selected engine without any HTTP calls.
update_option(Pixora::OPTION,array('automatic'=>1,'quality'=>72,'engine'=>'inplace','inplace_consent'=>1));
list($auto,$auto_path)=fixture_disk('jpg');$ids[]=$auto;
expect_disk(get_post_mime_type($auto)==='image/jpeg' && is_file($auto_path) && is_array(get_post_meta($auto,Pixora_Inplace::META,true)),'Upload follows same-format engine without WebP duplication');
update_option(Pixora::OPTION,array('automatic'=>0,'quality'=>72,'engine'=>'inplace','inplace_consent'=>0));
ob_start();Pixora::page();$page=ob_get_clean();
expect_disk(strpos($page,'data-pixora="inplace"')!==false,'Dashboard exposes disk-compatible action');
foreach($ids as $id) wp_delete_attachment($id,true);
echo 'Real WordPress '.get_bloginfo('version').'; PHP '.PHP_VERSION.'; Nginx static delivery; freed measured fixture bytes: '.$freed_total."\n";
