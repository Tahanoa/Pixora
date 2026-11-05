<?php
/** Real WordPress + GD integration. Run only in an isolated disposable WordPress install. */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
define('WP_HTTP_BLOCK_EXTERNAL', true);
define('WP_ACCESSIBLE_HOSTS', 'localhost');
define('DISABLE_WP_CRON', true);
if (in_array('--install', $argv, true)) define('WP_INSTALLING', true);
require '/var/www/html/wp-load.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
function verify($ok, $message) {
 if (!$ok) throw new RuntimeException($message);
 echo "PASS: $message\n";
}
if (in_array('--install', $argv, true)) {
 if (!is_blog_installed()) wp_install('Pixora integration', 'test-admin', 'test@example.invalid', true, '', wp_generate_password(32));
 update_option('home', 'http://localhost'); update_option('siteurl', 'http://localhost');
 $result = activate_plugin('pixora/pixora.php');
 verify(!is_wp_error($result), 'Plugin activates on real WordPress');
 exit;
}
wp_set_current_user(1);
function make_attachment($name) {
 $uploads = wp_upload_dir();
 $path = $uploads['path'] . '/' . wp_unique_filename($uploads['path'], $name . '.jpg');
 $image = imagecreatetruecolor(1200, 900);
 mt_srand(187);
 for ($y = 0; $y < 900; $y += 6) {
  for ($x = 0; $x < 1200; $x += 6) {
   imagefilledrectangle($image, $x, $y, $x + 5, $y + 5, imagecolorallocate($image, ($x + mt_rand(0, 20)) % 256, $y % 256, ($x + $y) % 256));
  }
 }
 imagejpeg($image, $path, 98); imagedestroy($image);
 $id = wp_insert_attachment(array('post_mime_type' => 'image/jpeg', 'post_title' => $name, 'post_status' => 'inherit'), $path, 0, true);
 verify(!is_wp_error($id), 'JPEG attachment inserted');
 $metadata = wp_generate_attachment_metadata($id, $path);
 wp_update_attachment_metadata($id, $metadata);
 return array($id, $path);
}
update_option(Pixora::OPTION, array('quality' => 82, 'automatic' => 0, 'auto_cleanup' => 0));
list($id, $original) = make_attachment('pixora-real-' . uniqid());
$original_meta = wp_get_attachment_metadata($id);
$legacy_url = wp_get_attachment_url($id);
$result = Pixora_Diagnostics::run($id, 'optimize');
verify(!is_wp_error($result), 'Real GD/Imagick WebP optimization succeeds' . (is_wp_error($result) ? ': ' . $result->get_error_message() : ''));
$webp = get_attached_file($id);
verify(wp_get_image_mime($webp) === 'image/webp' && get_post_mime_type($id) === 'image/webp', 'WebP bytes and attachment MIME agree');
verify(file_exists($original), 'Optimization preserves original until cleanup');
$srcset = wp_get_attachment_image_srcset($id, 'large');
verify(is_string($srcset) && strpos($srcset, '.webp') !== false && strpos($srcset, '.jpg') === false, 'WordPress srcset uses WebP sizes');
verify(Pixora::restore($id) === true, 'Real WordPress restoration succeeds');
verify(get_attached_file($id) === $original && wp_get_attachment_metadata($id) === $original_meta, 'Restoration recovers original file and metadata');
$result = Pixora_Diagnostics::run($id, 'optimize_reclaim');
verify(!is_wp_error($result), 'One-step optimization and cleanup succeeds' . (is_wp_error($result) ? ': ' . $result->get_error_message() : ''));
verify(!file_exists($original) && $result['freed'] > 0, 'One-step workflow really deletes originals and reports bytes');
$response = wp_remote_get($legacy_url);
verify(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_header($response, 'content-type') === 'image/webp', 'Legacy JPEG URL serves WebP through Apache after deletion');
verify(hash('sha256', wp_remote_retrieve_body($response)) === hash_file('sha256', get_attached_file($id)), 'Legacy response matches generated file exactly');
verify(is_wp_error(Pixora::restore($id)), 'Restore is blocked after irreversible deletion');
$html = Pixora::media_button(get_post($id));
verify(strpos($html, 'فضای آزادشده') !== false && strpos($html, 'data-pixora-mode="reclaim"') === false, 'Media library displays actual cleanup status');
// Opt-in is mandatory, and image upload remains separate from background deletion.
$settings = Pixora::sanitize(array('quality' => 82, 'automatic' => 1, 'auto_cleanup' => 1));
verify($settings['auto_cleanup'] === 0, 'Automatic deletion cannot be enabled without consent');
$settings = Pixora::sanitize(array('quality' => 82, 'automatic' => 1, 'auto_cleanup' => 1, 'cleanup_consent' => 1));
update_option(Pixora::OPTION, $settings);
list($auto, $auto_original) = make_attachment('pixora-auto-' . uniqid());
verify(get_post_mime_type($auto) === 'image/webp' && file_exists($auto_original), 'Upload automatically optimizes but defers deletion');
verify(wp_next_scheduled(Pixora_Workflow::HOOK, array($auto)) !== false, 'Upload schedules cleanup through WordPress Cron');
$queue = get_post_meta($auto, Pixora_Workflow::QUEUE, true);
verify($queue['state'] === 'pending', 'Queued cleanup state is visible');
$paused = $settings; $paused['auto_cleanup'] = 0; update_option(Pixora::OPTION, $paused);
Pixora_Workflow::process($auto);
verify(file_exists($auto_original), 'Turning off automatic cleanup prevents scheduled deletion');
update_option(Pixora::OPTION, $settings);
Pixora_Workflow::process($auto);
verify(!file_exists($auto_original) && get_post_meta($auto, Pixora_Workflow::QUEUE, true)['state'] === 'done', 'Background cleanup deletes only after verified routing');
// Scaled uploads must keep WordPress's original-image APIs valid after reclaiming both sources.
update_option(Pixora::OPTION, array('quality' => 82, 'automatic' => 0, 'auto_cleanup' => 0));
add_filter('big_image_size_threshold', function() { return 600; });
list($scaled_id, $full_original) = make_attachment('pixora-scaled-' . uniqid());
$scaled_file = get_attached_file($scaled_id);
verify($scaled_file !== $full_original && !empty(wp_get_attachment_metadata($scaled_id)['original_image']), 'WordPress scaled upload fixture retains full original');
$result = Pixora_Diagnostics::run($scaled_id, 'optimize_reclaim');
verify(!is_wp_error($result) && !file_exists($full_original) && !file_exists($scaled_file), 'One-step workflow reclaims scaled and full-resolution JPEG originals');
verify(is_file(wp_get_original_image_path($scaled_id)) && wp_get_image_mime(wp_get_original_image_path($scaled_id)) === 'image/webp', 'WordPress original-image API resolves retained full-resolution WebP');
remove_all_filters('big_image_size_threshold');
$uploads = wp_upload_dir();
$png_path = $uploads['path'] . '/pixora-alpha-' . uniqid() . '.png';
$png = imagecreatetruecolor(360, 260); imagealphablending($png, false); imagesavealpha($png, true);
mt_srand(47);
for ($y = 0; $y < 260; ++$y) for ($x = 0; $x < 360; ++$x) imagesetpixel($png, $x, $y, imagecolorallocatealpha($png, mt_rand(0,255), mt_rand(0,255), mt_rand(0,255), mt_rand(0,127)));
imagesetpixel($png, 0, 0, imagecolorallocatealpha($png, 0, 0, 0, 127));
imagepng($png, $png_path); imagedestroy($png);
$png_id = wp_insert_attachment(array('post_mime_type' => 'image/png', 'post_title' => 'Alpha PNG', 'post_status' => 'inherit'), $png_path);
wp_update_attachment_metadata($png_id, wp_generate_attachment_metadata($png_id, $png_path));
$result = Pixora_Diagnostics::run($png_id, 'optimize_reclaim');
verify(!is_wp_error($result) && !file_exists($png_path), 'Transparent PNG is optimized and reclaimed with real encoder');
$decoded = imagecreatefromwebp(get_attached_file($png_id));
verify(((imagecolorat($decoded, 0, 0) >> 24) & 0x7f) === 127, 'PNG transparency survives WebP conversion');
imagedestroy($decoded);
list($persian_id, $persian_original) = make_attachment('تصویر-پیکسورا-' . uniqid());
$result = Pixora_Diagnostics::run($persian_id, 'optimize_reclaim');
verify(!is_wp_error($result) && !file_exists($persian_original), 'Persian filenames support verified WebP cleanup on real Apache');
// Render actual settings page to catch missing functions and markup regressions.
ob_start(); Pixora::page(); $panel = ob_get_clean();
verify(strpos($panel, 'data-pixora="optimize_reclaim"') !== false && strpos($panel, 'auto_cleanup') !== false, 'Real admin panel exposes one-step and automatic cleanup controls');
// Exercise the actual authenticated AJAX endpoints with WordPress capabilities and nonces.
if (!defined('DOING_AJAX')) define('DOING_AJAX', true);
class Pixora_Test_Json_End extends RuntimeException {}
add_filter('wp_die_ajax_handler', function() { return function($message = '') { if ((string)$message === '-1') echo '-1'; throw new Pixora_Test_Json_End(); }; });
function ajax_result($payload, $user = 1, $valid_nonce = true, $batch = false) {
 wp_set_current_user($user);
 $_POST = $payload;
 $_REQUEST = $payload;
 $_REQUEST['nonce'] = $valid_nonce ? wp_create_nonce('pixora_batch') : 'invalid';
 ob_start();
 try { if ($batch) Pixora::batch(); else Pixora::single(); } catch (Pixora_Test_Json_End $end) {}
 $raw = ob_get_clean();
 return json_decode($raw, true) ?? $raw;
}
$subscriber = wp_insert_user(array('user_login' => 'pixora-subscriber-' . uniqid(), 'user_pass' => wp_generate_password(32), 'role' => 'subscriber'));
$author = wp_insert_user(array('user_login' => 'pixora-author-' . uniqid(), 'user_pass' => wp_generate_password(32), 'role' => 'author'));
verify(!ajax_result(array('id' => $png_id, 'mode' => 'reclaim', 'consent' => 'delete-originals'), $subscriber)['success'], 'Real AJAX denies subscriber media operations');
wp_update_post(array('ID' => $png_id, 'post_author' => $author));
verify(!ajax_result(array('id' => $png_id, 'mode' => 'reclaim', 'consent' => 'delete-originals'), $author)['success'], 'Real AJAX denies destructive operation to non-admin author');
verify(!ajax_result(array('id' => $png_id, 'mode' => 'optimize_reclaim'))['success'], 'Real AJAX requires explicit deletion consent');
verify(ajax_result(array('id' => $png_id), 1, false) === -1, 'Real AJAX rejects invalid nonce');
wp_set_current_user(1);
list($ajax_id, $ajax_original) = make_attachment('pixora-ajax-' . uniqid());
$ajax = ajax_result(array('mode' => 'optimize_reclaim', 'cursor' => $ajax_id - 1, 'consent' => 'delete-originals'), 1, true, true);
verify($ajax['success'] && $ajax['data']['cursor'] === $ajax_id && $ajax['data']['status'] === 'processed' && $ajax['data']['freed'] > 0, 'Real combined batch selects candidate and returns actual reclaimed bytes');
verify(!file_exists($ajax_original), 'Real combined AJAX batch removes the selected original');
list($retry_id, $retry_original) = make_attachment('pixora-retry-' . uniqid());
$block_probe = function() { return new WP_Error('test_probe', 'Intentional local probe failure'); };
add_filter('pre_http_request', $block_probe);
$failed = Pixora_Diagnostics::run($retry_id, 'optimize_reclaim');
remove_filter('pre_http_request', $block_probe);
verify(is_wp_error($failed) && $failed->get_error_code() === 'cleanup_pending' && file_exists($retry_original) && get_post_mime_type($retry_id) === 'image/webp', 'Probe failure retains original while keeping successful WebP conversion');
$generated_before = get_post_meta($retry_id, Pixora::BACKUP, true)['generated'];
$retry = Pixora_Diagnostics::run($retry_id, 'optimize_reclaim');
verify(!is_wp_error($retry) && !file_exists($retry_original) && get_post_meta($retry_id, Pixora::BACKUP, true)['generated'] === $generated_before, 'Retry reuses existing WebP without redundant conversion');
deactivate_plugins('pixora/pixora.php');
$response = wp_remote_get($legacy_url);
verify(!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200 && wp_remote_retrieve_header($response, 'content-type') === 'image/webp', 'Legacy URLs keep working after plugin deactivation');
activate_plugin('pixora/pixora.php');
Pixora_Workflow::deactivate();
verify(!wp_next_scheduled(Pixora_Workflow::HOOK, array($auto)), 'Deactivation clears scheduled cleanup');
update_option(Pixora::OPTION, array('quality' => 82, 'automatic' => 0, 'auto_cleanup' => 0));
wp_delete_attachment($id, true); wp_delete_attachment($auto, true); wp_delete_attachment($scaled_id, true); wp_delete_attachment($png_id, true); wp_delete_attachment($ajax_id, true); wp_delete_attachment($retry_id, true); wp_delete_attachment($persian_id, true);
echo 'WordPress ' . get_bloginfo('version') . '; PHP ' . PHP_VERSION . "; real integration complete\n";
