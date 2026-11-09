<?php
/** CLI-only filesystem assertions for browser-created attachments in a disposable site. */
if(PHP_SAPI!=='cli')exit;
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/';define('DISABLE_WP_CRON',true);
require '/var/www/html/wp-load.php';
$ids=json_decode(file_get_contents('/tmp/pixora-browser-uploaded.json'),true);
if(count($ids)!==4)throw new RuntimeException('Expected four browser-created media attachments');
foreach($ids as$id){
 $file=get_attached_file($id);$meta=wp_get_attachment_metadata($id);
 if(wp_get_image_mime($file)!=='image/webp'||get_post_mime_type($id)!=='image/webp'||get_post_meta($id,Pixora::BACKUP,true))throw new RuntimeException('Stored media is not standalone WebP');
 foreach(array('jpg','jpeg','png')as$ext)if(file_exists(preg_replace('/\.webp$/','.'.$ext,$file)))throw new RuntimeException('Unexpected original remains');
 foreach($meta['sizes']as$size)if(wp_get_image_mime(dirname($file).'/'.$size['file'])!=='image/webp')throw new RuntimeException('Non-WebP thumbnail');
 wp_delete_attachment($id,true);
}
echo "PASS: browser-created main files and thumbnails are WebP; no JPEG/PNG originals or Pixora backups remain\n";
