<?php
// Isolated workflow test. No WordPress installation or real encoder is implied.
define('ABSPATH', __DIR__);
$root = sys_get_temp_dir() . '/pixora-test-' . uniqid();
mkdir($root);
$GLOBALS['uploads'] = $root;
$GLOBALS['meta'] = array(); $GLOBALS['options'] = array();
$GLOBALS['mime'] = 'image/jpeg'; $GLOBALS['file'] = $root . '/sample.jpg';
class WP_Error { public $code; function __construct($code, $message = '') { $this->code = $code; } }
function is_wp_error($v) { return $v instanceof WP_Error; }
function __($s, $domain = '') { return $s; }
function add_filter() {} function add_action() {}
function wp_parse_args($a, $b) { return array_merge($b, $a); }
function get_option($k, $d = false) { return $GLOBALS['options'][$k] ?? $d; }
function add_option($k, $v, $deprecated = '', $autoload = null) { if (isset($GLOBALS['options'][$k])) return false; $GLOBALS['options'][$k] = $v; return true; }
function delete_option($k) { unset($GLOBALS['options'][$k]); }
function absint($v) { return abs((int)$v); }
function get_post_meta($id, $k, $single) { return $GLOBALS['meta'][$k] ?? ''; }
function add_post_meta($id, $k, $v, $unique) { if (isset($GLOBALS['meta'][$k])) return false; $GLOBALS['meta'][$k] = $v; return true; }
function delete_post_meta($id, $k) { unset($GLOBALS['meta'][$k]); }
function get_post_mime_type($id) { return $GLOBALS['mime']; }
function wp_image_editor_supports($args) { return true; }
function wp_get_upload_dir() { return array('basedir' => $GLOBALS['uploads']); }
function get_attached_file($id) { return $GLOBALS['file']; }
function wp_get_attachment_metadata($id) { return $GLOBALS['attachment']; }
function wp_update_attachment_metadata($id, $v) { $GLOBALS['attachment'] = $v; return true; }
function update_attached_file($id, $v) { if (!empty($GLOBALS['fail_write'])) { $GLOBALS['fail_write'] = false; return false; } $GLOBALS['file'] = $v; return true; }
function wp_update_post($v) { $GLOBALS['mime'] = $v['post_mime_type']; return $v['ID']; }
function _wp_relative_upload_path($v) { return basename($v); }
function wp_unique_filename($dir, $name) { $i = 0; $base = $name; while (file_exists($dir . '/' . $name)) $name = ++$i . '-' . $base; return $name; }
function wp_delete_file($file) { unlink($file); }
class FakeEditor {
 function set_quality($q) { return true; }
 function save($target, $mime) {
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
 check(Pixora::sanitize(array('quality' => 200))['quality'] === 100, 'Quality is bounded');
} finally {
 foreach (glob($root . '/*') as $path) unlink($path);
 rmdir($root);
}
