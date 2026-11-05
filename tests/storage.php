<?php
// Uses the existing WordPress API doubles; no real Apache or hosting compatibility is claimed.
require __DIR__ . '/workflow.php';
define('MB_IN_BYTES', 1048576);
function update_post_meta($id, $key, $value) {
 if (!empty($GLOBALS['fail_meta'])) return false;
 $GLOBALS['meta'][$key] = $value; return true;
}
function trailingslashit($v) { return rtrim($v, '/') . '/'; }
function wp_generate_uuid4() { return uniqid(); }
function add_query_arg($key, $value, $url) { return $url . '?' . $key . '=' . $value; }
function home_url() { return 'https://example.test'; }
function site_url() { return 'https://example.test'; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
class WP_Http { public static function make_absolute_url($location, $base) {
 if (strpos($location, '://') !== false) return $location;
 $p = parse_url($base);
 return $p['scheme'] . '://' . $p['host'] . ($location[0] === '/' ? $location : dirname($p['path']) . '/' . $location);
} }
class FakeBatchDB {
 public $posts = 'wp_posts'; public $postmeta = 'wp_postmeta'; public $query; public $ids = array();
 function prepare($query, ...$args) { $this->query = $query; return $query; }
 function get_col($query) { return $this->ids; }
}
function wp_safe_remote_get($url, $args) {
 $GLOBALS['requests'][] = array($url, $args);
 if (!empty($GLOBALS['redirect_queue'])) return array_shift($GLOBALS['redirect_queue']);
 if ($GLOBALS['probe_mode'] === 'error') return new WP_Error('network');
 $source = basename(parse_url($url, PHP_URL_PATH));
 $target = $GLOBALS['mapping'][$source];
 return array('code' => 200, 'type' => $GLOBALS['probe_mode'] === 'mime' ? 'image/jpeg' : 'image/webp', 'body' => $GLOBALS['probe_mode'] === 'hash' ? 'wrong-image' : file_get_contents($target));
}
function wp_remote_retrieve_response_code($r) { return $r['code']; }
function wp_remote_retrieve_header($r, $key) { return $key === 'location' ? ($r['location'] ?? '') : ($r['type'] ?? ''); }
function wp_remote_retrieve_body($r) { return $r['body']; }
function fixture() {
 $dir = $GLOBALS['uploads'];
 $GLOBALS['file'] = $dir . '/sample.jpg';
 $GLOBALS['mime'] = 'image/jpeg'; $GLOBALS['meta'] = array();
 $GLOBALS['attachment'] = array('file' => 'sample.jpg', 'sizes' => array('thumbnail' => array('file' => 'sample-150.jpg', 'mime-type' => 'image/jpeg')));
 file_put_contents($dir . '/sample.jpg', str_repeat('j', 1000));
 file_put_contents($dir . '/sample-150.jpg', str_repeat('j', 500));
 $result = Pixora::optimize(1);
 if (is_wp_error($result)) throw new Exception('fixture failed');
 $b = get_post_meta(1, Pixora::BACKUP, true);
 $GLOBALS['mapping'] = array('sample.jpg' => $b['generated'][0], 'sample-150.jpg' => $b['generated'][1]);
 return $b;
}
$root = sys_get_temp_dir() . '/pixora-storage-' . uniqid();
mkdir($root); $GLOBALS['uploads'] = $root;
// Override baseurl in the workflow double through a shared variable.
$GLOBALS['probe_mode'] = 'ok'; $GLOBALS['requests'] = array();
$previous = "# Existing host policy\nOptions -Indexes\n";
try {
 $_SERVER['SERVER_SOFTWARE'] = 'nginx/1.26';
 check(!Pixora_Storage::host()['supported'], 'Nginx does not enable automatic cleanup');
 $_SERVER['SERVER_SOFTWARE'] = '';
 check(!Pixora_Storage::host()['supported'], 'Unknown host fails closed');
 $_SERVER['SERVER_SOFTWARE'] = 'LiteSpeed';
 check(Pixora_Storage::host()['supported'], 'LiteSpeed is detected automatically');
 $_SERVER['SERVER_SOFTWARE'] = 'Apache/2.4';
 check(Pixora_Storage::host()['supported'], 'Apache is detected automatically');
 $backup = fixture();
 file_put_contents($root . '/.htaccess', $previous);
 foreach (array('error', 'mime', 'hash') as $mode) {
  $GLOBALS['probe_mode'] = $mode;
  check(is_wp_error(Pixora_Storage::reclaim(1)), 'Failed probe is rejected: ' . $mode);
  check(file_exists($root . '/sample.jpg') && file_exists($root . '/sample-150.jpg'), 'Probe failure preserves all originals: ' . $mode);
  check(file_get_contents($root . '/.htaccess') === $previous, 'Probe failure restores existing host rules: ' . $mode);
 }
 $GLOBALS['probe_mode'] = 'ok'; $GLOBALS['fail_meta'] = true;
 check(is_wp_error(Pixora_Storage::reclaim(1)) && file_exists($root . '/sample.jpg'), 'Database failure prevents deletion');
 check(file_get_contents($root . '/.htaccess') === $previous, 'Database failure restores host rules');
 $GLOBALS['fail_meta'] = false;
 $_POST = array('mode' => 'reclaim');
 try { Pixora::batch(); } catch (JsonResponse $r) { check(!$r->ok, 'Cleanup endpoint requires explicit consent'); }
 $file = $backup['generated'][0];
 $GLOBALS['redirect_queue'] = array(array('code' => 301, 'location' => 'https://example.test/uploads/sample.jpg'), array('code' => 200, 'type' => 'image/webp', 'body' => file_get_contents($file)));
 $probe = Pixora_Storage::probe('http://example.test/uploads/sample.jpg', $file);
 check(!is_wp_error($probe) && $probe['code'] === 200 && $probe['body'] === file_get_contents($file), 'Canonical HTTP to HTTPS redirect is followed');
 $GLOBALS['redirect_queue'] = array(array('code' => 301, 'location' => 'https://untrusted.test/image.webp'));
 check(is_wp_error(Pixora_Storage::probe('https://example.test/uploads/sample.jpg', $file)), 'Redirect to unconfigured host is rejected');
 $GLOBALS['redirect_queue'] = array(array('code' => 301, 'location' => 'http://example.test/uploads/sample.jpg'));
 check(is_wp_error(Pixora_Storage::probe('https://example.test/uploads/sample.jpg', $file)), 'HTTPS downgrade is rejected');
 $GLOBALS['redirect_queue'] = array(array('code' => 301, 'location' => '/uploads/sample.jpg'));
 check(is_wp_error(Pixora_Storage::probe('https://example.test/uploads/sample.jpg', $file)), 'Redirect loop is rejected');
 $GLOBALS['redirect_queue'] = array();
 $result = Pixora_Storage::reclaim(1);
 check(!is_wp_error($result) && $result['freed'] === 1500 && $result['remaining'] === 0, 'Verified cleanup reports actual deleted bytes');
 check(!file_exists($root . '/sample.jpg') && !file_exists($root . '/sample-150.jpg'), 'Verified cleanup deletes original and thumbnail');
 check(file_exists($backup['generated'][0]) && file_exists($backup['generated'][1]), 'Cleanup preserves WebP outputs');
 $rules = file_get_contents($root . '/.htaccess');
 if (getenv('PIXORA_TEST_EXPORT')) {
  $export = getenv('PIXORA_TEST_EXPORT');
  if (!is_dir($export)) mkdir($export, 0755, true);
  file_put_contents($export . '/.htaccess', $rules);
  foreach ($backup['generated'] as $path) copy($path, $export . '/' . basename($path));
 }

 check(strpos($rules, 'RewriteRule "^sample\\.jpg$') !== false && strpos($rules, $previous) !== false, 'Persistent rules retain legacy URLs and existing host policy');
 check(is_wp_error(Pixora::restore(1)), 'Restoration is blocked after irreversible cleanup');
 check(is_wp_error(Pixora_Storage::reclaim(1)), 'Repeated cleanup does not delete outputs');
 check(get_post_meta(1, Pixora::BACKUP, true)['disk_freed'] === 1500, 'Disk reclamation is persisted separately from payload savings');
 $GLOBALS['wpdb'] = new FakeBatchDB();
 $_POST = array('mode' => 'reclaim', 'consent' => 'delete-originals');
 try { Pixora::batch(); } catch (JsonResponse $r) { check($r->ok && $r->data['done'], 'Cleanup finishes immediately when no optimized attachments exist'); }
 check(strpos($GLOBALS['wpdb']->query, 'AND EXISTS') !== false && strpos($GLOBALS['wpdb']->query, 'pm.post_id = p.ID') !== false, 'Cleanup query requires Pixora backup metadata');
 $GLOBALS['wpdb']->ids = array(1);
 try { Pixora::batch(); } catch (JsonResponse $r) { check($r->ok && !empty($r->data['scanning']), 'Previously reclaimed attachments are silently skipped'); }
 $_POST = array('mode' => 'optimize'); $GLOBALS['wpdb']->ids = array();
 try { Pixora::batch(); } catch (JsonResponse $r) { check($r->ok && $r->data['done'], 'Optimization handles no candidates'); }
 check(strpos($GLOBALS['wpdb']->query, 'NOT EXISTS') !== false && strpos($GLOBALS['wpdb']->query, "'image/webp'") === false, 'Optimization excludes previously optimized and WebP attachments');
 check($GLOBALS['requests'][0][1]['redirection'] === 0, 'Probe does not accept redirected responses');
} finally {
 foreach (array_merge(glob($root . '/*'), glob($root . '/.pixora-*.lock')) as $path) unlink($path);
 if (file_exists($root . '/.htaccess')) unlink($root . '/.htaccess');
 rmdir($root);
}
