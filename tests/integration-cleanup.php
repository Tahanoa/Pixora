<?php
/** Destructive fixtures only; never run on a production WordPress install. */
$_SERVER['HTTP_HOST']='localhost';$_SERVER['SERVER_SOFTWARE']='Apache/2.4';$_SERVER['REQUEST_URI']='/';$_SERVER['REQUEST_METHOD']='GET';
define('WP_HTTP_BLOCK_EXTERNAL',true);define('WP_ACCESSIBLE_HOSTS','localhost');define('DISABLE_WP_CRON',true);
require '/var/www/html/wp-load.php';require_once ABSPATH.'wp-admin/includes/image.php';require_once ABSPATH.'wp-admin/includes/template.php';
wp_set_current_user(1);
function cleanup_check($ok,$text){if(!$ok)throw new RuntimeException($text);echo "PASS: $text\n";}
update_option(Pixora::OPTION,array('automatic'=>0,'quality'=>82,'engine'=>'webp','auto_cleanup'=>0));
$ids=array();$posts=array();$extra=array();
function cleanup_fixture($converted=true){
 $u=wp_upload_dir();$file=$u['path'].'/pixora-clean-'.uniqid().'.jpg';$im=imagecreatetruecolor(800,600);
 for($y=0;$y<600;$y+=8)for($x=0;$x<800;$x+=8)imagefilledrectangle($im,$x,$y,$x+7,$y+7,imagecolorallocate($im,$x%256,$y%256,($x+$y)%256));
 imagejpeg($im,$file,99);imagedestroy($im);
 $id=wp_insert_attachment(array('post_title'=>'Cleanup fixture','post_mime_type'=>'image/jpeg','post_status'=>'inherit'),$file);
 wp_update_attachment_metadata($id,wp_generate_attachment_metadata($id,$file));
 if($converted){$r=Pixora::optimize($id);if(is_wp_error($r))throw new RuntimeException($r->get_error_message());}
 $GLOBALS['ids'][]=$id;return array($id,$file);
}
try{
 list($a,$original)=cleanup_fixture();$backup=get_post_meta($a,Pixora::BACKUP,true);$plan=Pixora_Webp_Cleanup::plan($a);
 cleanup_check(!is_wp_error($plan)&&$plan['eligible']&&$plan['potential']>0,'Preview identifies tracked copies with available originals');
 cleanup_check(get_post_mime_type($a)==='image/webp'&&file_exists($backup['generated'][0]),'Preview changes no files or attachment pointers');
 $expected=$plan['potential'];$result=Pixora_Webp_Cleanup::purge($a);
 cleanup_check(!is_wp_error($result)&&$result['freed']===$expected&&$result['deleted']===count($backup['generated']),'Purge deletes tracked copies and reports actual removed bytes');
 cleanup_check(get_attached_file($a)===$original&&get_post_mime_type($a)==='image/jpeg','Purge first returns the attachment to its existing JPEG');
 foreach($backup['generated']as$file)cleanup_check(!file_exists($file),'Deleted generated copy: '.basename($file));
 cleanup_check(file_exists($original)&&!get_post_meta($a,Pixora::BACKUP,true)&&!get_post_meta($a,Pixora_Webp_Cleanup::COPIES,true),'Originals remain and completed cleanup leaves no stale ledger');
 list($b,$b_original)=cleanup_fixture();$b_backup=get_post_meta($b,Pixora::BACKUP,true);
 $ref=wp_insert_post(array('post_type'=>'post','post_status'=>'publish','post_title'=>'Reference','post_content'=>'<img src="'.wp_get_attachment_url($b).'">'));$posts[]=$ref;
 $blocked=Pixora_Webp_Cleanup::purge($b);
 cleanup_check(is_wp_error($blocked)&&$blocked->get_error_code()==='referenced'&&file_exists($b_backup['generated'][0]),'Published content reference blocks deletion');
 wp_delete_post($ref,true);
 $u=wp_upload_dir();$css=$u['path'].'/cleanup-reference.css';$extra[]=$css;file_put_contents($css,'body{background:url('.wp_get_attachment_url($b).')}');
 cleanup_check(is_wp_error(Pixora_Webp_Cleanup::purge($b))&&file_exists($b_backup['generated'][0]),'Generated CSS reference blocks deletion');unlink($css);
 cleanup_check(Pixora::restore($b)===true&&is_array(get_post_meta($b,Pixora_Webp_Cleanup::COPIES,true)),'Normal restore keeps a cleanup ledger for retained WebP files');
 $removed=Pixora_Webp_Cleanup::purge($b);cleanup_check(!is_wp_error($removed)&&$removed['deleted']>0&&get_attached_file($b)===$b_original,'Previously restored tracked copies can be cleaned later');
 list($only,$only_original)=cleanup_fixture();$only_backup=get_post_meta($only,Pixora::BACKUP,true);unlink($only_original);
 cleanup_check(is_wp_error(Pixora_Webp_Cleanup::purge($only))&&file_exists($only_backup['generated'][0]),'Only remaining WebP is protected when original is missing');
 // Own redirect must be detached before deleting its target; unrelated Pixora rules remain.
 list($rule_id,$rule_original)=cleanup_fixture();$rule_backup=get_post_meta($rule_id,Pixora::BACKUP,true);
 $rule_path=dirname($rule_original).'/.htaccess';$rule_previous=is_file($rule_path)?file_get_contents($rule_path):'';
 $own_rule=Pixora_Storage::rule($rule_original,$rule_backup['generated'][0],wp_get_upload_dir());
 $other_rule="# BEGIN Pixora-999999\nRewriteEngine On\nRewriteRule ^unrelated-test.jpg$ unrelated-test.webp [R=307,L]\n# END Pixora-999999\n";
 file_put_contents($rule_path,"# BEGIN Pixora-$rule_id\nRewriteEngine On\n".$own_rule."\n# END Pixora-$rule_id\n".$other_rule.$rule_previous);
 $rule_purge=Pixora_Webp_Cleanup::purge($rule_id);
 cleanup_check(!is_wp_error($rule_purge)&&strpos(file_get_contents($rule_path),'# BEGIN Pixora-'.$rule_id)===false&&strpos(file_get_contents($rule_path),'# BEGIN Pixora-999999')!==false,'Cleanup removes only its own redirect and preserves unrelated rules');
 $rule_url=wp_get_attachment_url($rule_id);$response=wp_remote_get($rule_url);
 cleanup_check(!is_wp_error($response)&&wp_remote_retrieve_response_code($response)===200&&wp_remote_retrieve_header($response,'content-type')==='image/jpeg','Original URL remains a working JPEG after redirect removal');
 file_put_contents($rule_path,$rule_previous);
 list($partial_id,$partial_original)=cleanup_fixture();$partial_backup=get_post_meta($partial_id,Pixora::BACKUP,true);
 $deny_delete=function($file)use($partial_backup){return in_array($file,$partial_backup['generated'],true)?'':$file;};
 add_filter('wp_delete_file',$deny_delete);$partial=Pixora_Webp_Cleanup::purge($partial_id);remove_filter('wp_delete_file',$deny_delete);
 cleanup_check(!is_wp_error($partial)&&$partial['deleted']===0&&$partial['remaining']===count($partial_backup['generated'])&&is_array(get_post_meta($partial_id,Pixora_Webp_Cleanup::COPIES,true)),'Failed file deletion preserves a retryable copy ledger');
 $retried=Pixora_Webp_Cleanup::purge($partial_id);cleanup_check(!is_wp_error($retried)&&$retried['deleted']===count($partial_backup['generated']),'Retry removes remaining copies without losing original media');
 list($persist_id,$persist_original)=cleanup_fixture();$persist_backup=get_post_meta($persist_id,Pixora::BACKUP,true);$persist_webp=get_attached_file($persist_id);
 $deny_pointer=function($check,$post,$key){return $key==='_wp_attached_file'?false:$check;};add_filter('update_post_metadata',$deny_pointer,10,3);
 $persist_result=Pixora_Webp_Cleanup::purge($persist_id);remove_filter('update_post_metadata',$deny_pointer,10);
 cleanup_check(is_wp_error($persist_result)&&get_attached_file($persist_id)===$persist_webp&&get_post_mime_type($persist_id)==='image/webp'&&file_exists($persist_backup['generated'][0]),'Failed pointer restoration rolls back metadata and preserves WebP copies');
 // An uploaded WebP without a Pixora ledger must never be deleted by this feature.
 $raw=$u['path'].'/original-upload-'.uniqid().'.webp';$im=imagecreatetruecolor(40,40);imagewebp($im,$raw);imagedestroy($im);
 $raw_id=wp_insert_attachment(array('post_mime_type'=>'image/webp','post_status'=>'inherit'),$raw);$ids[]=$raw_id;wp_update_attachment_metadata($raw_id,wp_generate_attachment_metadata($raw_id,$raw));
 cleanup_check(is_wp_error(Pixora_Webp_Cleanup::purge($raw_id))&&file_exists($raw),'Original uploaded WebP is never treated as a Pixora copy');
 list($selected,$selected_original)=cleanup_fixture(false);list($unselected,$unselected_original)=cleanup_fixture(false);
 $_REQUEST['_wpnonce']=wp_create_nonce('bulk-media');
 $redirect=Pixora_Bulk::handle('http://localhost/wp-admin/upload.php','pixora_webp',array($selected));parse_str(wp_parse_url($redirect,PHP_URL_QUERY),$query);
 $token=$query['pixora_job'];$job=Pixora_Bulk::get($token);
 cleanup_check(!is_wp_error($job)&&$job['ids']===array($selected)&&$job['mode']==='optimize','Media bulk selection creates an owner-scoped job with exact selected IDs');
 $_GET=array('pixora_job'=>$token);ob_start();Pixora::page();$page=ob_get_clean();cleanup_check(strpos($page,'id="pixora-selection"')!==false,'Selected-media review page renders its scope token');$_GET=array();
 if(!defined('DOING_AJAX'))define('DOING_AJAX',true);
 class Cleanup_Json_End extends RuntimeException{}
 add_filter('wp_die_ajax_handler',function(){return function($message=''){if((string)$message==='-1')echo '-1';throw new Cleanup_Json_End();};});
 function cleanup_ajax($payload,$user=1,$valid=true){wp_set_current_user($user);$_POST=$payload;$_REQUEST=array('nonce'=>$valid?wp_create_nonce('pixora_batch'):'bad');ob_start();try{Pixora::batch();}catch(Cleanup_Json_End $end){}$raw=ob_get_clean();return json_decode($raw,true)??$raw;}
 $selected_result=cleanup_ajax(array('mode'=>'optimize','selection'=>$token,'cursor'=>0));
 cleanup_check($selected_result['success']&&$selected_result['data']['cursor']===$selected&&get_post_mime_type($selected)==='image/webp'&&get_post_mime_type($unselected)==='image/jpeg','Selected batch changes only the selected media');
 cleanup_check(cleanup_ajax(array('mode'=>'optimize','selection'=>$token,'cursor'=>$selected))['data']['done'],'Selection job finishes without scanning unrelated media');
 cleanup_check(!cleanup_ajax(array('mode'=>'purge_webp','selection'=>$token,'cursor'=>0,'consent'=>'delete-pixora-webp'))['success'],'Selection cannot be reused for a different destructive operation');
 cleanup_check(!cleanup_ajax(array('mode'=>'purge_webp','cursor'=>0))['success'],'Purge requires its separate explicit consent');
 cleanup_check(cleanup_ajax(array('mode'=>'preview_webp_cleanup','cursor'=>0),1,false)===-1,'Cleanup preview rejects invalid nonce');
 $_REQUEST['_wpnonce']=wp_create_nonce('bulk-media');$copy_redirect=Pixora_Bulk::handle('http://localhost','pixora_remove_webp',array($selected));parse_str(wp_parse_url($copy_redirect,PHP_URL_QUERY),$copy_query);$copy_token=$copy_query['pixora_job'];
 $preview=cleanup_ajax(array('mode'=>'preview_webp_cleanup','selection'=>$copy_token,'cursor'=>0));
 cleanup_check($preview['success']&&$preview['data']['potential']>0&&$preview['data']['freed']===0,'Selected cleanup preview reports potential bytes without claiming deletion');
 $purged=cleanup_ajax(array('mode'=>'purge_webp','selection'=>$copy_token,'cursor'=>0,'consent'=>'delete-pixora-webp'));
 cleanup_check($purged['success']&&$purged['data']['deleted']>0&&$purged['data']['freed']===$preview['data']['potential']&&get_post_mime_type($unselected)==='image/jpeg','Selected cleanup endpoint deletes only its planned copies and reports real savings');
 $other=wp_insert_user(array('user_login'=>'cleanup-other-'.uniqid(),'user_pass'=>wp_generate_password(32),'role'=>'administrator'));wp_set_current_user($other);
 cleanup_check(is_wp_error(Pixora_Bulk::get($token)),'Another administrator cannot use someone else\'s selection token');
 wp_set_current_user(1);
 // Strict WebP action must not silently use JPEG fallback when routing is unavailable.
 $block=function(){return new WP_Error('probe_test','Blocked test HTTP');};add_filter('pre_http_request',$block);
 $strict=Pixora_Diagnostics::run($unselected,'webp_delete');remove_filter('pre_http_request',$block);
 cleanup_check(is_wp_error($strict)&&get_post_mime_type($unselected)==='image/jpeg'&&file_exists($unselected_original),'Strict WebP-and-delete action preserves original on failed routing');
 $before_hash=hash_file('sha256',$unselected_original);
 add_filter('pre_http_request',$block);
 $combined=Pixora_Diagnostics::run($unselected,'optimize_reclaim');
 remove_filter('pre_http_request',$block);
 cleanup_check(is_wp_error($combined)&&hash_file('sha256',$unselected_original)===$before_hash,'Combined action never replaces JPEG after failed routing');
 $old_options=get_option(Pixora::OPTION,array());
 update_option(Pixora::OPTION,array('engine'=>'inplace','automatic'=>1,'inplace_consent'=>1));
 cleanup_check(!Pixora::options()['automatic'],'Legacy same-format preference does not silently enable WebP uploads');
 update_option(Pixora::OPTION,array('automatic'=>1,'auto_cleanup'=>1));
 add_filter('pre_http_request',$block);
 $upload_metadata=wp_get_attachment_metadata($unselected);
 $upload_result=Pixora::on_upload($upload_metadata,$unselected);
 remove_filter('pre_http_request',$block);
 cleanup_check($upload_result===$upload_metadata&&hash_file('sha256',$unselected_original)===$before_hash,'Upload with failed WebP preflight leaves JPEG unchanged');
 update_option(Pixora::OPTION,$old_options);
 cleanup_check(is_wp_error(Pixora_Diagnostics::run($unselected,'inplace')),'Removed engine cannot be invoked through diagnostics');
 $actions=Pixora_Bulk::actions(array());cleanup_check(isset($actions['pixora_webp'],$actions['pixora_webp_delete'],$actions['pixora_remove_webp']),'Media list exposes conversion, strict deletion and copy-cleanup bulk actions');
 echo 'Real WebP cleanup and selected-media tests complete; WordPress '.get_bloginfo('version')."\n";
}finally{
 wp_set_current_user(1);foreach($posts as$id)wp_delete_post($id,true);foreach($extra as$file)if(is_file($file))unlink($file);foreach($ids as$id)wp_delete_attachment($id,true);
}
