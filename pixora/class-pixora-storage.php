<?php
/** Optional disk reclamation with static, independently verified URL compatibility. */
if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pixora_Storage {
 public static function host() {
  $software = isset( $_SERVER['SERVER_SOFTWARE'] ) ? strtolower( (string) $_SERVER['SERVER_SOFTWARE'] ) : '';
  if ( false !== strpos( $software, 'litespeed' ) ) { return array( 'supported' => true, 'name' => 'LiteSpeed' ); }
  if ( false !== strpos( $software, 'apache' ) ) { return array( 'supported' => true, 'name' => 'Apache' ); }
  if ( false !== strpos( $software, 'nginx' ) ) { return array( 'supported' => false, 'name' => 'Nginx' ); }
  return array( 'supported' => false, 'name' => 'نامشخص' );
 }
 // Follow only bounded canonical redirects on configured site/upload hosts.
 public static function probe( $url, $file ) {
  $allowed = array();
  $uploads = wp_get_upload_dir();
  foreach ( array( $uploads['baseurl'], home_url(), site_url() ) as $base ) {
   $host = strtolower( (string) wp_parse_url( $base, PHP_URL_HOST ) );
   if ( $host ) { $allowed[] = $host; }
  }
  $visited = array();
  for ( $hop = 0; $hop <= 3; ++$hop ) {
   $parts = wp_parse_url( $url );
   if ( ! is_array( $parts ) || ! in_array( strtolower( $parts['host'] ?? '' ), $allowed, true ) || ! in_array( $parts['scheme'] ?? '', array( 'http', 'https' ), true ) || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['fragment'] ) || ( isset( $parts['port'] ) && ! in_array( (int) $parts['port'], array( 80, 443 ), true ) ) ) {
    return new WP_Error( 'redirect', 'هدایت آدرس به مقصد تأییدنشده بود؛ اصل تصویر حفظ شد.' );
   }
   if ( isset( $visited[ $url ] ) ) { return new WP_Error( 'redirect', 'حلقهٔ هدایت آدرس تشخیص داده شد؛ اصل تصویر حفظ شد.' ); }
   $visited[ $url ] = true;
   $response = wp_safe_remote_get( $url, array( 'timeout' => 8, 'redirection' => 0, 'limit_response_size' => filesize( $file ) + 1, 'headers' => array( 'Cache-Control' => 'no-cache', 'Accept' => 'image/webp' ) ) );
   if ( is_wp_error( $response ) ) { return $response; }
   $code = wp_remote_retrieve_response_code( $response );
   if ( ! in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) { return $response; }
   $location = wp_remote_retrieve_header( $response, 'location' );
   if ( ! is_string( $location ) || '' === $location ) { return new WP_Error( 'redirect', 'پاسخ هدایت، آدرس مقصد ندارد.' ); }
   $next = WP_Http::make_absolute_url( $location, $url );
   if ( 'https' === ( $parts['scheme'] ?? '' ) && 'https' !== wp_parse_url( $next, PHP_URL_SCHEME ) ) {
    return new WP_Error( 'redirect', 'هدایت از HTTPS به HTTP پذیرفته نشد؛ اصل تصویر حفظ شد.' );
   }
   $url = $next;
  }
  return new WP_Error( 'redirect', 'تعداد هدایت‌های آدرس بیش از حد مجاز است؛ اصل تصویر حفظ شد.' );
 }
 public static function rule( $source, $target, $uploads ) {
  $relative = substr( realpath( $target ), strlen( realpath( $uploads['basedir'] ) ) + 1 );
  $destination = trailingslashit( $uploads['baseurl'] ) . str_replace( DIRECTORY_SEPARATOR, '/', $relative );
  if ( preg_match( '/[\x00-\x20"\\\\%?#]/', $destination ) ) { return new WP_Error( 'url', 'آدرس بارگذاری برای هدایت خودکار مناسب نیست.' ); }
  return 'RewriteRule "^' . preg_quote( basename( $source ), '/' ) . '$" "' . $destination . '" [R=307,L]';
 }
 public static function preflight( $file ) {
  if ( ! self::host()['supported'] || ! Pixora::safe_file( $file ) ) { return new WP_Error( 'preflight', 'هاست برای آزادسازی خودکار تأیید نشد؛ برای جلوگیری از افزایش مصرف دیسک، تبدیل انجام نشد.' ); }
  $uploads = wp_get_upload_dir(); $dir = dirname( $file );
  $token = str_replace( '-', '', wp_generate_uuid4() );
  $source = $dir . '/pixora-probe-' . $token . '.png';
  $target = $dir . '/pixora-probe-' . $token . '.webp';
  $created = array(); $handle = null; $previous = null;
  try {
   foreach ( array( $source => base64_decode( 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAADElEQVQImWM4oREAAAL8AUHoivCJAAAAAElFTkSuQmCC', true ), $target => base64_decode( 'UklGRjgAAABXRUJQVlA4ICwAAABwAQCdASoBAAEAAUAiJaACdAFAAAD+8DhBhhME6/+9gv/92C//3YL/WwAAAA==', true ) ) as $path => $body ) {
    $probe = @fopen( $path, 'x' );
    if ( ! $probe ) { return new WP_Error( 'preflight', 'ایجاد فایل بررسی هاست ممکن نشد؛ تبدیل انجام نشد.' ); }
    $created[] = $path;
    $written = fwrite( $probe, $body ); fclose( $probe );
    if ( strlen( $body ) !== $written ) { return new WP_Error( 'preflight', 'نوشتن فایل بررسی هاست کامل نشد.' ); }
   }
   $path = $dir . '/.htaccess';
   if ( is_link( $path ) ) { return new WP_Error( 'preflight', 'مسیر تنظیم هاست قابل تغییر نیست.' ); }
   $handle = @fopen( $path, 'c+' );
   if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { return new WP_Error( 'preflight', 'فایل تنظیم هاست در دسترس نیست؛ تبدیل انجام نشد.' ); }
   $previous = stream_get_contents( $handle );
   if ( false === $previous || strlen( $previous ) > MB_IN_BYTES ) { $previous = null; return new WP_Error( 'preflight', 'خواندن تنظیم هاست ممکن نشد.' ); }
   $rule = self::rule( $source, $target, $uploads );
   if ( is_wp_error( $rule ) ) { return $rule; }
   $content = "# BEGIN Pixora route check\n<IfModule mod_rewrite.c>\nRewriteEngine On\n" . $rule . "\n</IfModule>\n# END Pixora route check\n" . $previous;
   if ( ! self::write( $handle, $content ) ) { return new WP_Error( 'preflight', 'ذخیرهٔ تنظیم آزمایشی هاست ممکن نشد.' ); }
   $relative = substr( realpath( $source ), strlen( realpath( $uploads['basedir'] ) ) + 1 );
   $url = trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( DIRECTORY_SEPARATOR, $relative ) ) );
   $response = self::probe( add_query_arg( 'pixora_check', $token, $url ), $target );
   if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || 'image/webp' !== strtolower( trim( explode( ';', wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) ) || ! hash_equals( hash_file( 'sha256', $target ), hash( 'sha256', wp_remote_retrieve_body( $response ) ) ) ) {
    return new WP_Error( 'preflight', 'هاست یا کش، هدایت به WebP را تأیید نکرد؛ برای جلوگیری از افزایش فضای دیسک، نسخهٔ جدید ساخته نشد. کش تصاویر یا CDN را پاک کنید یا تنظیم هدایت را با پشتیبانی هاست بررسی کنید.' );
   }
   return true;
  } finally {
   if ( is_resource( $handle ) ) {
    if ( null !== $previous ) { self::write( $handle, $previous ); }
    flock( $handle, LOCK_UN ); fclose( $handle );
   }
   foreach ( $created as $path ) { if ( Pixora::safe_file( $path ) ) { wp_delete_file( $path ); } }
  }
 }
 public static function reclaim( $id ) {
  if ( ! self::host()['supported'] ) { return new WP_Error( 'host', 'هاست سازگار با تنظیم خودکار تشخیص داده نشد؛ فایل‌های اصلی حفظ شدند.' ); }
  $lock = Pixora::acquire_lock( $id );
  if ( is_wp_error( $lock ) ) { return $lock; }
  $handle = null; $previous = null; $committed = false;
  try {
   $backup = get_post_meta( $id, Pixora::BACKUP, true );
   if ( ! is_array( $backup ) ) { return new WP_Error( 'state', 'این تصویر با Pixora بهینه نشده است؛ اصل آن حذف نمی‌شود.' ); }
   if ( ! empty( $backup['reclaimed'] ) ) { return new WP_Error( 'state', 'آزادسازی این تصویر قبلاً آغاز یا انجام شده است؛ برای جلوگیری از حذف تکراری رد شد.' ); }
   $sources = array( $backup['file'] );
   foreach ( $backup['metadata']['sizes'] ?? array() as $size ) { $sources[] = dirname( $backup['file'] ) . '/' . $size['file']; }
   $sources = isset( $backup['sources'] ) && is_array( $backup['sources'] ) ? $backup['sources'] : array_values( array_unique( $sources ) );
   $targets = $backup['generated'];
   if ( count( $sources ) !== count( $targets ) ) { return new WP_Error( 'mapping', 'اطلاعات نگاشت کامل نیست؛ فایل‌های اصلی حفظ شدند.' ); }
   $uploads = wp_get_upload_dir();
   $dir = dirname( $backup['file'] );
   $rules = array( '<IfModule mod_rewrite.c>', 'RewriteEngine On' );
   $checks = array();
   foreach ( $sources as $index => $source ) {
    $target = $targets[ $index ];
    // Restrict automatic rule generation to unambiguous filenames and one real directory.
    if ( ! Pixora::safe_file( $source ) || ! Pixora::safe_file( $target ) || is_link( $source ) || is_link( $target ) || dirname( $source ) !== $dir || dirname( $target ) !== $dir || ! preg_match( '/\A[\p{L}\p{N}\p{M}_. -]+\z/u', basename( $source ) ) || ! preg_match( '/\A[\p{L}\p{N}\p{M}_. -]+\.webp\z/u', basename( $target ) ) || filesize( $target ) > 20 * MB_IN_BYTES ) {
     return new WP_Error( 'files', 'این فایل برای آزادسازی خودکار مناسب نیست؛ اصل تصویر حفظ شد. نام فایل نباید نویسهٔ کنترلی یا علامت‌های ویژهٔ قواعد هدایت داشته باشد.' );
    }
    if ( $source === $target || realpath( $source ) === realpath( $target ) ) { return new WP_Error( 'mapping', 'نگاشت نامعتبر است.' ); }
    $rule = self::rule( $source, $target, $uploads );
    if ( is_wp_error( $rule ) ) { return $rule; }
    $rules[] = $rule;
    $relative = substr( realpath( $source ), strlen( realpath( $uploads['basedir'] ) ) + 1 );
    $checks[] = array( 'url' => trailingslashit( $uploads['baseurl'] ) . implode( '/', array_map( 'rawurlencode', explode( DIRECTORY_SEPARATOR, $relative ) ) ), 'file' => $target, 'source' => $source, 'hash' => hash_file( 'sha256', $source ) );
   }
   $rules[] = '</IfModule>';
   $path = $dir . '/.htaccess';
   if ( is_link( $path ) || ( file_exists( $path ) && ! is_writable( $path ) ) || ! is_writable( $dir ) ) { return new WP_Error( 'rules', 'هاست اجازهٔ تنظیم هدایت آدرس‌ها را نمی‌دهد؛ فایل اصلی حفظ شد.' ); }
   $handle = @fopen( $path, 'c+' );
   if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { return new WP_Error( 'rules', 'فایل تنظیم هدایت در دسترس نیست؛ دوباره تلاش کنید.' ); }
   $previous = stream_get_contents( $handle );
   if ( false === $previous || strlen( $previous ) > MB_IN_BYTES ) { $previous = null; return new WP_Error( 'rules', 'خواندن تنظیم هدایت ممکن نشد.' ); }
   $marker = 'Pixora-' . absint( $id );
   if ( false !== strpos( $previous, '# BEGIN ' . $marker . "\n" ) ) { return new WP_Error( 'rules', 'قواعد قبلی این تصویر نیازمند بررسی هستند؛ فایل اصلی حفظ شد.' ); }
   $content = '# BEGIN ' . $marker . "\n" . implode( "\n", $rules ) . "\n# END " . $marker . "\n" . $previous;
   if ( ! self::write( $handle, $content ) ) { return new WP_Error( 'rules', 'ذخیرهٔ هدایت آدرس‌ها ممکن نشد.' ); }
   // Do not trust software detection, a writable .htaccess, a status code, or a cached original.
   // Every legacy URL must return the exact WebP bytes with the correct MIME type.
   foreach ( $checks as $check ) {
    Pixora_Diagnostics::stage( 'آزمایش آدرس ' . basename( $check['source'] ) );
    $response = self::probe( add_query_arg( 'pixora_check', wp_generate_uuid4(), $check['url'] ), $check['file'] );
    if ( is_wp_error( $response ) || 200 !== wp_remote_retrieve_response_code( $response ) || 'image/webp' !== strtolower( trim( explode( ';', wp_remote_retrieve_header( $response, 'content-type' ) )[0] ) ) || ! hash_equals( hash_file( 'sha256', $check['file'] ), hash( 'sha256', wp_remote_retrieve_body( $response ) ) ) ) {
     $detail = is_wp_error( $response ) ? $response->get_error_message() : sprintf( 'HTTP %d؛ نوع محتوا: %s؛ محتوای فایل: %s', wp_remote_retrieve_response_code( $response ), wp_remote_retrieve_header( $response, 'content-type' ), hash_equals( hash_file( 'sha256', $check['file'] ), hash( 'sha256', wp_remote_retrieve_body( $response ) ) ) ? 'WebP درست است اما نوع محتوا یا وضعیت پاسخ نادرست است' : 'فایل WebP ارسال نشد؛ احتمال کش یا اعمال‌نشدن هدایت' );
     return new WP_Error( 'probe', 'آزمایش آدرس قدیمی موفق نبود؛ اصل تصاویر حفظ شدند. ' . $detail );
    }
   }
   // Persist the irreversible state before deleting anything. Static rules survive plugin deactivation.
   $backup['reclaimed'] = true;
   $backup['disk_freed'] = 0;
   $backup['rules_file'] = $path;
   if ( ! update_post_meta( $id, Pixora::BACKUP, $backup ) ) { return new WP_Error( 'database', 'ثبت وضعیت آزادسازی ممکن نشد؛ هیچ فایل اصلی حذف نشد.' ); }
   $committed = true;
   $freed = 0; $remaining = 0;
   foreach ( $checks as $check ) {
    if ( ! Pixora::safe_file( $check['source'] ) || ! hash_equals( $check['hash'], hash_file( 'sha256', $check['source'] ) ) ) { ++$remaining; continue; }
    $bytes = filesize( $check['source'] );
    wp_delete_file( $check['source'] );
    clearstatcache( true, $check['source'] );
    if ( ! file_exists( $check['source'] ) ) { $freed += $bytes; } else { ++$remaining; }
   }
   $backup['disk_freed'] = $freed;
   $backup['remaining_originals'] = $remaining;
   update_post_meta( $id, Pixora::BACKUP, $backup );
   return array( 'freed' => $freed, 'remaining' => $remaining );
  } finally {
   if ( is_resource( $handle ) ) {
    if ( ! $committed && null !== $previous ) { self::write( $handle, $previous ); }
    flock( $handle, LOCK_UN ); fclose( $handle );
   }
   Pixora::release_lock( $lock );
  }
 }
 private static function write( $handle, $content ) {
  rewind( $handle );
  $written = fwrite( $handle, $content );
  return strlen( $content ) === $written && ftruncate( $handle, strlen( $content ) ) && fflush( $handle );
 }
}
