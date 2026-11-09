<?php
/** CLI only, disposable test site. Never deploy to a live site. */
if (PHP_SAPI !== 'cli') exit;
$_SERVER['HTTP_HOST']='localhost';$_SERVER['REQUEST_URI']='/';$_SERVER['SERVER_SOFTWARE']='Apache/2.4';
define('DISABLE_WP_CRON',true);require '/var/www/html/wp-load.php';
require_once ABSPATH.'wp-admin/includes/image.php';
wp_set_current_user(1);
update_option(Pixora::OPTION,array('upload_webp_only'=>1,'browser_upload'=>1,'quality'=>82,'automatic'=>0));
$token=WP_Session_Tokens::get_instance(1)->create(time()+3600);
$data=array('cookies'=>array(
 array('name'=>AUTH_COOKIE,'value'=>wp_generate_auth_cookie(1,time()+3600,'auth',$token),'url'=>'http://localhost'),
 array('name'=>LOGGED_IN_COOKIE,'value'=>wp_generate_auth_cookie(1,time()+3600,'logged_in',$token),'url'=>'http://localhost')
));
file_put_contents('/tmp/pixora-browser-auth.json',wp_json_encode($data));chmod('/tmp/pixora-browser-auth.json',0600);
echo "Disposable browser session prepared\n";
