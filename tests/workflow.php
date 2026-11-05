<?php
// Isolated workflow test. No WordPress installation or real encoder is implied.
define('ABSPATH', __DIR__);
define('HOUR_IN_SECONDS', 3600);
$root = sys_get_temp_dir() . '/pixora-test-' . uniqid();
mkdir($root);
$GLOBALS['uploads'] = $root;
$GLOBALS['meta'] = array(); $GLOBALS['options'] = array();
$GLOBALS['mime'] = 'image/jpeg'; $GLOBALS['file'] = $root . '/sample.jpg';
class WP_Error { function get_error_message() { return $this->code; } public $code; function __construct($code, $message = '') { $this->code = $code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s, $domain = '') { return $s; }
function add_filter() {} function add_action() {}
function current_user_can($cap, $id = null) { return empty($GLOBALS['deny']); }
function esc_attr($v) { return htmlspecialchars((string)$v, ENT_QUOTES); }
function get_post_type($id) { return $id === 1 ? 'attachment' : 'post'; }
function check_ajax_referer($action, $key) { $GLOBALS['nonce_checked'] = true; }
class JsonResponse extends Exception { public $ok; public $data; function __construct($ok, $data) { $this->ok = $ok; $this->data = $data; } }
function wp_send_json_error($data, $status = 200) { throw new JsonResponse(false, $data); }
function wp_send_json_success($data) { throw new JsonResponse(true, $data); }
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function add_option($k, $v, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$k])) return false; $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); }
function size_format($v) { return $v . ' B'; }
function absint($v) { return abs((int)$v); }
function get_post_meta($id, $k, $single) { return $GLOBALS['meta'][$k] ?? ''; }
function add_post_meta($id, $k, $v, $unique) { if (isset($GLOBALS['meta'][$k])) return false; $GLOBALS['meta'][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$k]); }
function get_post_mime_type($id) { return $GLOBALS['mime']; }
function wp_image_editor_supports($args) { return true; }
function wp_get_upload_dir() { return array('basedir' => $GLOBALS['uploads'], 'baseurl' => 'https://example.test/uploads'); }
function get_attached_file($id) { return $GLOBALS['file']; }
function wp_get_attachment_metadata($id) { return $GLOBALS['attachment']; }
function wp_update_attachment_metadata($id, $v) { $GLOBALS['attachment'] = $v; return true; }
function update_attached_file($id, $v) { if (!empty($GLOBALS['fail_write'])) { $GLOBALS['fail_write'] = false; return false; } $GLOBALS['file'] = $v; return true; }
function wp_update_post($v) { $GLOBALS['mime'] = $v['post_mime_type']; return $v['ID']; }
function _wp_relative_upload_path($v) { return basename($v); }
function wp_unique_filename($dir, $name) { $i = 0; $base = $name; while (file_exists($dir . '/' . $name)) $name = ++$i . '-' . $base; return $name; }
function wp_delete_file($file) { unlink($file); }
class FakeEditor {
 function __construct() { $GLOBALS['editors_active'] = ($GLOBALS['editors_active'] ?? 0) + 1; $GLOBALS['editors_peak'] = max($GLOBALS['editors_peak'] ?? 0, $GLOBALS['editors_active']); }
 function __destruct() { --$GLOBALS['editors_active']; }
 function set_quality($q) { return true; }
 function save($target, $mime) {
  if (!empty($GLOBALS['throw_encode'])) throw new RuntimeException('Simulated encoder failure');
  if (!empty($GLOBALS['fail_encode'])) return new WP_Error('encoder');
  file_put_contents($target, str_repeat('w', !empty($GLOBALS['larger']) ? 2000 : 100));
  return array('path' => $target, 'mime-type' => $mime);
 }
}
function wp_get_image_editor($p) { return new FakeEditor(); }
function check($ok, $message) { if (!$ok) throw new Exception($message); echo "PASS: $message\n"; }
require __DIR__ . '/../pixora/pixora.php';
file_put_contents($root . '/sample.jpg', str_repeat('j', 1000));
file_put_contents($root . '/sample-150.jpg', str_repeat('j', 500));
$original = array('file' => 'sample.jpg', 'sizes' => array('thumbnail' => array('file' => 'sample-150.jpg', 'mime-type' => 'image/jpeg')));
$GLOBALS['attachment'] = $original;
try {
 $new = Pixora::optimize(1);
 check(!is_wp_error($new) && $GLOBALS['mime'] === 'image/webp', 'Optimization changes attachment MIME');
 check($GLOBALS['editors_peak'] === 1, 'Only one decoded image editor is retained at a time');
 check($new['sizes']['thumbnail']['mime-type'] === 'image/webp', 'Thumbnail metadata uses WebP');
 check(file_exists($root . '/sample.jpg') && file_exists($root . '/sample-150.jpg'), 'Original URLs remain available');
 check($GLOBALS['meta'][Pixora::BACKUP]['before'] === 1500 && $GLOBALS['meta'][Pixora::BACKUP]['after'] === 200, 'Savings include all sizes');
 check(is_wp_error(Pixora::optimize(1)), 'Repeated optimization is skipped');
 $webp = $GLOBALS['file'];
 check(Pixora::restore(1) === true && $GLOBALS['attachment'] === $original && $GLOBALS['mime'] === 'image/jpeg', 'Restoration recovers metadata and MIME');
 check(file_exists($webp), 'Restoration retains published WebP URLs');
 $GLOBALS['fail_write'] = true;
 check(is_wp_error(Pixora::optimize(1)) && $GLOBALS['attachment'] === $original && $GLOBALS['mime'] === 'image/jpeg' && !get_post_meta(1, Pixora::BACKUP, true), 'Database failure rolls back attachment state');
 add_option('pixora_lock_1', time());
 check(is_wp_error(Pixora::optimize(1)), 'Concurrent conversion is rejected');
 delete_option('pixora_lock_1');
 $GLOBALS['larger'] = true;
 check(is_wp_error(Pixora::optimize(1)) && $GLOBALS['file'] === $root . '/sample.jpg', 'Larger outputs do not replace original');
 check(count(glob($root . '/*.webp')) === 2, 'Unsuccessful conversion removes generated files');
 $GLOBALS['larger'] = false; $GLOBALS['fail_encode'] = true;
 check(is_wp_error(Pixora::optimize(1)) && !isset($GLOBALS['options']['pixora_lock_1']), 'Encoder failure releases lock');
 $GLOBALS['fail_encode'] = false;
 unlink($root . '/sample-150.jpg');
 check(is_wp_error(Pixora::optimize(1)) && !get_post_meta(1, Pixora::BACKUP, true), 'Missing thumbnail preserves original state');
 check(!Pixora::safe_file(__FILE__), 'Files outside uploads are rejected');
 $post = (object)array('ID' => 1, 'post_mime_type' => 'image/jpeg');
 check(strpos(Pixora::media_button($post), 'data-pixora-id="1"') !== false, 'Media action targets selected attachment');
 check(isset(Pixora::media_fields(array(), $post)['pixora']), 'Grid details expose Pixora control');
 check(isset(Pixora::media_actions(array(), $post)['pixora']), 'List view exposes Pixora control');
 $GLOBALS['deny'] = true;
 check(Pixora::media_button($post) === '', 'Unauthorized users do not see media action');
 $_POST['id'] = 1;
 try { Pixora::single(); } catch (JsonResponse $response) { check(!$response->ok && !empty($GLOBALS['nonce_checked']), 'Single action verifies nonce and denies unauthorized access'); }
 $GLOBALS['deny'] = false;
 $_POST['id'] = 2;
 try { Pixora::single(); } catch (JsonResponse $response) { check(!$response->ok, 'Single action rejects non-attachment posts'); }
 file_put_contents($root . '/sample-150.jpg', str_repeat('j', 500));
 $_POST['id'] = 1;
 try { Pixora::single(); } catch (JsonResponse $response) { check($response->ok && $GLOBALS['mime'] === 'image/webp', 'Single action optimizes selected media'); }
 check(strpos(Pixora::media_button($post), 'data-pixora-id') === false, 'Optimized media shows status instead of repeated action');
 $held = Pixora::acquire_lock(1);
 check(is_resource($held) && is_wp_error(Pixora::acquire_lock(1)), 'Live file lock prevents concurrent workers');
 Pixora::release_lock($held);
 $again = Pixora::acquire_lock(1);
 check(is_resource($again), 'Released file lock can be reacquired');
 Pixora::release_lock($again);
 add_option('pixora_lock_1', time() - 7200);
 $recovered = Pixora::acquire_lock(1);
 check(is_resource($recovered) && get_option('pixora_lock_1') === false, 'Stale legacy lock recovers automatically');
 Pixora::release_lock($recovered);
 Pixora_Diagnostics::begin(1, 'optimize');
 Pixora_Diagnostics::stage('test-stage');
 $diagnostic = Pixora_Diagnostics::message(array('message' => 'Allowed memory size exhausted', 'file' => '/private/server/image.php', 'line' => 22));
 Pixora_Diagnostics::finish();
 check(strpos($diagnostic, '#1') !== false && strpos($diagnostic, 'test-stage') !== false && strpos($diagnostic, '/private') === false, 'Fatal diagnostics identify image and stage without exposing server paths');
 Pixora::restore(1);
 $GLOBALS['throw_encode'] = true;
 check(is_wp_error(Pixora_Diagnostics::run(1, 'optimize')), 'Encoder exception becomes an actionable per-image error');
 $GLOBALS['throw_encode'] = false;
 $after_exception = Pixora::acquire_lock(1);
 check(is_resource($after_exception), 'Encoder exception releases processing lock');
 Pixora::release_lock($after_exception);
 check(Pixora::sanitize(array('quality' => 200))['quality'] === 100, 'Quality is bounded');
} finally {
 foreach (array_merge(glob($root . '/*'), glob($root . '/.pixora-*.lock')) as $path) unlink($path);
 rmdir($root);
}
