<?php
/** Delete only tracked Pixora copies after restoring usable originals and checking local references. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Webp_Cleanup {
 const COPIES = '_pixora_webp_copies';
 public static function remember( $id, $files ) {
  $old = get_post_meta( $id, self::COPIES, true );
  $all = array_values( array_unique( array_merge( is_array( $old ) ? $old : array(), $files ) ) );
  update_post_meta( $id, self::COPIES, $all );
  return get_post_meta( $id, self::COPIES, true ) === $all;
 }
 public static function originals( $id, $backup ) {
  $file = is_array( $backup ) ? $backup['file'] : get_attached_file( $id );
  $metadata = is_array( $backup ) ? $backup['metadata'] : wp_get_attachment_metadata( $id );
  $mime = is_array( $backup ) ? $backup['mime'] : get_post_mime_type( $id );
  if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) || ! is_array( $metadata ) ) { return new WP_Error( 'no_original', 'اصل JPEG/PNG برای این پیوست موجود نیست؛ تنها نسخهٔ WebP حذف نمی‌شود.' ); }
  $files = array( $file );
  foreach ( $metadata['sizes'] ?? array() as $size ) { $files[] = dirname( $file ) . '/' . $size['file']; }
  if ( ! empty( $metadata['original_image'] ) ) { $files[] = dirname( $file ) . '/' . $metadata['original_image']; }
  foreach ( array_unique( $files ) as $path ) {
   if ( ! Pixora::safe_file( $path ) || is_link( $path ) || ! in_array( wp_get_image_mime( $path ), array( 'image/jpeg', 'image/png' ), true ) || ! @getimagesize( $path ) ) { return new WP_Error( 'no_original', 'اصل یا یکی از اندازه‌های JPEG/PNG پیدا یا تأیید نشد؛ WebP برای حفظ تصویر نگهداری شد.' ); }
  }
  return array( 'file' => $file, 'metadata' => $metadata, 'mime' => $mime );
 }
 public static function strip_rule( $text, $id ) {
  $pattern = '/^# BEGIN Pixora-' . absint( $id ) . '\r?\n.*?^# END Pixora-' . absint( $id ) . '(?:\r?\n|$)/ms';
  $clean = preg_replace( $pattern, '', $text );
  if ( null === $clean || preg_match( '/^# BEGIN Pixora-' . absint( $id ) . '\r?$/m', $clean ) ) { return new WP_Error( 'rules', 'قواعد هدایت این تصویر ناقص است؛ پیش از حذف باید بررسی شود.' ); }
  return $clean;
 }
 private static function needles( $files ) {
  $needles = array();
  foreach ( $files as $file ) {
   $base = basename( $file );
   $needles[] = $base; $needles[] = rawurlencode( $base );
   $needles[] = trim( wp_json_encode( $base ), '"' );
  }
  return array_values( array_unique( $needles ) );
 }
 private static function contains( $text, $needles ) {
  foreach ( $needles as $needle ) { if ( false !== strpos( $text, $needle ) ) { return true; } }
  return false;
 }
 public static function references( $id, $files ) {
  global $wpdb;
  $needles = self::needles( $files );
  if ( ! $needles ) { return ''; }
  // Own bookkeeping and soon-to-be-restored attachment pointers are not published references.
  $checks = array(
   array( $wpdb->posts, array( 'post_content', 'post_excerpt' ), '' ),
   array( $wpdb->posts, array( 'guid' ), $wpdb->prepare( ' AND ID<>%d', $id ) ),
   array( $wpdb->postmeta, array( 'meta_value' ), $wpdb->prepare( " AND NOT (post_id=%d AND (meta_key LIKE %s OR meta_key IN ('_wp_attached_file','_wp_attachment_metadata')))", $id, $wpdb->esc_like( '_pixora_' ) . '%' ) ),
   array( $wpdb->options, array( 'option_value' ), '' ),
   array( $wpdb->termmeta, array( 'meta_value' ), '' ),
   array( $wpdb->usermeta, array( 'meta_value' ), '' ),
   array( $wpdb->commentmeta, array( 'meta_value' ), '' ),
  );
  foreach ( $checks as $check ) {
   $clauses = array(); $args = array();
   foreach ( $check[1] as $column ) { foreach ( $needles as $needle ) { $clauses[] = "$column LIKE %s"; $args[] = '%' . $wpdb->esc_like( $needle ) . '%'; } }
   $found = $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . $check[0] . ' WHERE (' . implode( ' OR ', $clauses ) . ')' . $check[2] . ' LIMIT 1', $args ) );
   if ( $wpdb->last_error ) { return new WP_Error( 'reference_check', 'بررسی ارجاع‌های پایگاه داده کامل نشد؛ فایل‌ها نگهداری شدند.' ); }
   if ( $found ) { return 'یکی از این کپی‌ها در محتوا، تنظیمات یا اطلاعات رسانهٔ دیگری ارجاع دارد؛ برای حفظ لینک‌ها کپی‌های این پیوست نگهداری شدند.'; }
  }
  $uploads = wp_get_upload_dir(); $root = realpath( $uploads['basedir'] );
  // Check ancestor .htaccess files, after removing only the rule owned by this attachment.
  $dirs = array();
  foreach ( $files as $file ) {
   $dir = dirname( $file );
   while ( $dir && '.' !== $dir && '/' !== $dir ) {
    $dirs[ $dir ] = true;
    if ( $dir === $root || $dir === rtrim( ABSPATH, '/\\' ) ) { break; }
    $next = dirname( $dir ); if ( $next === $dir ) { break; } $dir = $next;
   }
  }
  $site_rules = rtrim( ABSPATH, '/\\' ) . '/.htaccess';
  foreach ( array_merge( array_map( function( $dir ) { return $dir . '/.htaccess'; }, array_keys( $dirs ) ), array( $site_rules ) ) as $path ) {
   if ( ! file_exists( $path ) ) { continue; }
   if ( is_link( $path ) || ! is_readable( $path ) || filesize( $path ) > MB_IN_BYTES ) { return new WP_Error( 'reference_check', 'بررسی فایل هدایت کامل نشد؛ کپی‌ها نگهداری شدند.' ); }
   $raw = file_get_contents( $path );
   if ( false === $raw ) { return new WP_Error( 'reference_check', 'خواندن قواعد هدایت کامل نشد.' ); }
   $text = self::strip_rule( $raw, $id );
   if ( is_wp_error( $text ) ) { return $text; }
   if ( self::contains( $text, $needles ) || preg_match( '/^(?!#).*?(?:RewriteRule|RewriteCond|Redirect).*?(?:\\.webp|webp\b)/mi', preg_replace( '/^# BEGIN Pixora-(\d+)\r?\n.*?^# END Pixora-\1(?:\r?\n|$)/ms', '', $text ) ) ) { return 'قواعد هدایت دیگری به WebP وابسته‌اند؛ پیش از حذف باید بررسی شوند.'; }
  }
  // Check generated upload assets and the common page-cache directory; bound work on shared hosts.
  $count = 0; $bytes = 0;
  try {
   $roots = array( $root );
   if ( defined( 'WP_CONTENT_DIR' ) && is_dir( WP_CONTENT_DIR . '/cache' ) ) { $roots[] = realpath( WP_CONTENT_DIR . '/cache' ); }
   foreach ( array_unique( $roots ) as $asset_root ) {
   $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $asset_root, FilesystemIterator::SKIP_DOTS ) );
   foreach ( $iterator as $entry ) {
    if ( $entry->isLink() || ! $entry->isFile() || ! preg_match( '/\.(css|js|html?|json)$/i', $entry->getFilename() ) ) { continue; }
    $size = $entry->getSize(); ++$count; $bytes += $size;
    if ( $count > 500 || $size > 2 * MB_IN_BYTES || $bytes > 20 * MB_IN_BYTES || ! $entry->isReadable() ) { return new WP_Error( 'reference_check', 'حجم یا تعداد فایل‌های متنی uploads برای بررسی کامل زیاد است؛ کپی‌ها حذف نشدند.' ); }
    $content = file_get_contents( $entry->getPathname() );
    if ( false === $content ) { return new WP_Error( 'reference_check', 'خواندن فایل‌های متنی کامل نشد.' ); }
    if ( self::contains( $content, $needles ) ) { return 'در فایل CSS/JS/HTML/JSON مربوط به uploads یا کش صفحه ارجاع به WebP وجود دارد؛ کپی‌ها برای حفظ نمایش تصویر باقی ماندند.'; }
   }
   }
  } catch ( UnexpectedValueException $error ) { return new WP_Error( 'reference_check', 'بخشی از uploads قابل خواندن نبود؛ حذف انجام نشد.' ); }
  return '';
 }
 public static function plan( $id ) {
  $backup = get_post_meta( $id, Pixora::BACKUP, true );
  $backup = is_array( $backup ) ? $backup : null;
  $history = get_post_meta( $id, self::COPIES, true );
  $files = array_values( array_unique( array_merge( is_array( $history ) ? $history : array(), $backup['generated'] ?? array() ) ) );
  if ( ! $files ) { return new WP_Error( 'state', 'کپی WebP قابل ردیابی از Pixora برای این پیوست وجود ندارد.' ); }
  $originals = self::originals( $id, $backup );
  if ( is_wp_error( $originals ) ) { return $originals; }
  $existing = array(); $hashes = array(); $bytes = 0;
  foreach ( $files as $path ) {
   if ( ! file_exists( $path ) ) { continue; }
   if ( ! Pixora::safe_file( $path ) || is_link( $path ) || 'webp' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) || 'image/webp' !== wp_get_image_mime( $path ) ) { return new WP_Error( 'invalid_copy', 'مسیر یا نوع یکی از کپی‌ها معتبر نیست؛ حذف انجام نشد.' ); }
   $existing[] = $path; $hashes[ $path ] = hash_file( 'sha256', $path ); $bytes += filesize( $path );
  }
  if ( count( $existing ) > 128 ) { return new WP_Error( 'limit', 'تعداد کپی‌های این پیوست بیش از حد بررسی هر درخواست است.' ); }
  $reference = self::references( $id, $existing );
  if ( is_wp_error( $reference ) ) { return $reference; }
  return array( 'backup' => $backup, 'originals' => $originals, 'files' => $existing, 'hashes' => $hashes, 'eligible' => '' === $reference, 'potential' => '' === $reference ? $bytes : 0, 'count' => count( $existing ), 'message' => $reference ?: sprintf( '%d کپی قابل پاک‌سازی؛ حجم بررسی اولیه: %s.', count( $existing ), size_format( $bytes ) ) );
 }
 public static function purge( $id ) {
  $lock = Pixora::acquire_lock( $id ); if ( is_wp_error( $lock ) ) { return $lock; }
  $handle = null; $previous = null; $changed = false; $ready = false; $pointers_changed = false; $pointer_before = null;
  try {
   $plan = self::plan( $id );
   if ( is_wp_error( $plan ) ) { return $plan; }
   if ( ! $plan['eligible'] ) { return new WP_Error( 'referenced', $plan['message'] ); }
   if ( ! self::remember( $id, $plan['files'] ) ) { return new WP_Error( 'persist', 'ثبت فهرست کپی‌ها پیش از حذف ممکن نشد.' ); }
   $rule_file = dirname( $plan['originals']['file'] ) . '/.htaccess';
   if ( file_exists( $rule_file ) ) {
    if ( is_link( $rule_file ) || ! is_writable( $rule_file ) ) { return new WP_Error( 'rules', 'امکان بررسی/حذف قواعد این پیوست وجود ندارد.' ); }
    $handle = @fopen( $rule_file, 'r+' );
    if ( ! $handle || ! flock( $handle, LOCK_EX | LOCK_NB ) ) { return new WP_Error( 'rules', 'قواعد هاست در حال تغییر است؛ دوباره تلاش کنید.' ); }
    $previous = stream_get_contents( $handle );
    if ( false === $previous || strlen( $previous ) > MB_IN_BYTES ) { $previous = null; return new WP_Error( 'rules', 'خواندن قواعد کامل نشد.' ); }
    $clean = self::strip_rule( $previous, $id );
    if ( is_wp_error( $clean ) ) { return $clean; }
    if ( $clean !== $previous ) { $changed = true; rewind( $handle ); if ( fwrite( $handle, $clean ) !== strlen( $clean ) || ! ftruncate( $handle, strlen( $clean ) ) || ! fflush( $handle ) ) { return new WP_Error( 'rules', 'حذف قواعد قبلی کامل نشد؛ فایل‌ها نگهداری شدند.' ); } $changed = true; }
   }
   $originals = $plan['originals'];
   $pointer_before = array( 'file' => get_attached_file( $id ), 'mime' => get_post_mime_type( $id ), 'metadata' => wp_get_attachment_metadata( $id ) );
   $pointers_changed = true;
   update_attached_file( $id, $originals['file'] );
   wp_update_post( array( 'ID' => $id, 'post_mime_type' => $originals['mime'] ) );
   wp_update_attachment_metadata( $id, $originals['metadata'] );
   if ( get_attached_file( $id ) !== $originals['file'] || get_post_mime_type( $id ) !== $originals['mime'] || wp_get_attachment_metadata( $id ) !== $originals['metadata'] ) { return new WP_Error( 'persist', 'بازگشت پیوست به JPEG/PNG تأیید نشد؛ کپی‌ها حذف نشدند.' ); }
   $check = self::references( $id, $plan['files'] );
   if ( is_wp_error( $check ) ) { return $check; }
   if ( $check ) { return new WP_Error( 'referenced', $check ); }
   $verified_originals = self::originals( $id, $plan['backup'] );
   if ( is_wp_error( $verified_originals ) ) { return $verified_originals; }
   $ready = true; $freed = 0; $deleted = 0; $remaining = array();
   foreach ( $plan['files'] as $path ) {
    if ( ! Pixora::safe_file( $path ) || is_link( $path ) || ! hash_equals( $plan['hashes'][ $path ], hash_file( 'sha256', $path ) ) ) { $remaining[] = $path; continue; }
    $bytes = filesize( $path ); wp_delete_file( $path ); clearstatcache( true, $path );
    if ( file_exists( $path ) ) { $remaining[] = $path; } else { $freed += $bytes; ++$deleted; }
   }
   update_post_meta( $id, self::COPIES, $remaining );
   $journal_pending = get_post_meta( $id, self::COPIES, true ) !== $remaining;
   if ( get_post_meta( $id, self::COPIES, true ) === $remaining ) { delete_post_meta( $id, Pixora::BACKUP ); if ( ! $remaining ) { delete_post_meta( $id, self::COPIES ); } }
   wp_clear_scheduled_hook( Pixora_Workflow::HOOK, array( (int) $id ) ); delete_post_meta( $id, Pixora_Workflow::QUEUE );
   return array( 'freed' => $freed, 'deleted' => $deleted, 'remaining' => count( $remaining ), 'journal_pending' => $journal_pending, 'message' => sprintf( '%d کپی WebP حذف شد؛ %s آزاد شد. پیوست اکنون از JPEG/PNG موجود استفاده می‌کند. %d کپی حذف نشد.', $deleted, size_format( $freed ), count( $remaining ) ) . ( $journal_pending ? ' ثبت نهایی سابقه کامل نشد؛ پس از رفع دسترسی دیتابیس دوباره اجرا کنید.' : '' ) );
  } finally {
   if ( $pointers_changed && ! $ready && is_array( $pointer_before ) ) { update_attached_file( $id, $pointer_before['file'] ); wp_update_post( array( 'ID' => $id, 'post_mime_type' => $pointer_before['mime'] ) ); wp_update_attachment_metadata( $id, $pointer_before['metadata'] ); }
   if ( is_resource( $handle ) ) { if ( $changed && ! $ready && null !== $previous ) { rewind( $handle ); fwrite( $handle, $previous ); ftruncate( $handle, strlen( $previous ) ); fflush( $handle ); } flock( $handle, LOCK_UN ); fclose( $handle ); }
   Pixora::release_lock( $lock );
  }
 }
}
