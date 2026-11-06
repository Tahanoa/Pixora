<?php
/** Same-format, atomic disk optimization. Does not require HTTP routing or a remote API. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Inplace {
 const META = '_pixora_inplace';
 public static function keep_format( $formats ) { return array(); }
 public static function animated( $file ) {
  $handle = @fopen( $file, 'rb' );
  if ( ! $handle ) { return true; }
  try {
   if ( "\x89PNG\r\n\x1a\n" !== fread( $handle, 8 ) ) { return true; }
   while ( ! feof( $handle ) ) {
    $header = fread( $handle, 8 );
    if ( 8 !== strlen( $header ) ) { return true; }
    $chunk = unpack( 'Nlength/a4type', $header );
    if ( 'acTL' === $chunk['type'] ) { return true; }
    if ( 'IDAT' === $chunk['type'] ) { return false; }
    if ( 'IEND' === $chunk['type'] || 0 !== fseek( $handle, $chunk['length'] + 4, SEEK_CUR ) ) { return true; }
   }
  } finally { fclose( $handle ); }
  return true;
 }
 public static function optimize( $id ) {
  $lock = Pixora::acquire_lock( $id );
  if ( is_wp_error( $lock ) ) { return $lock; }
  $temporary = array();
  try {
   if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'image' ); }
   $backup = get_post_meta( $id, Pixora::BACKUP, true );
   $metadata = wp_get_attachment_metadata( $id );
   $file = get_attached_file( $id );
   // Existing WebP conversions may still have JPEG/PNG originals consuming disk space.
   if ( is_array( $backup ) && empty( $backup['reclaimed'] ) ) {
    $file = $backup['file']; $metadata = $backup['metadata'];
   }
   if ( ! is_array( $metadata ) || ! Pixora::safe_file( $file ) ) { return new WP_Error( 'missing', 'فایل یا اطلاعات رسانه برای کاهش فضای دیسک موجود نیست.' ); }
   $sources = array( $file );
   foreach ( $metadata['sizes'] ?? array() as $size ) { $sources[] = dirname( $file ) . '/' . $size['file']; }
   if ( ! empty( $metadata['original_image'] ) ) { $sources[] = dirname( $file ) . '/' . $metadata['original_image']; }
   $sources = array_unique( $sources );
   $state = get_post_meta( $id, self::META, true );
   $state = is_array( $state ) ? $state : array( 'files' => array() );
   $freed = 0; $skipped = 0; $issues = array();
   foreach ( $sources as $source ) {
    if ( ! Pixora::safe_file( $source ) || is_link( $source ) || ! is_writable( $source ) || ! is_writable( dirname( $source ) ) ) { $issues[] = 'فایل موجود یا قابل نوشتن نیست: ' . basename( $source ); continue; }
    $mime = wp_get_image_mime( $source );
    if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) { ++$skipped; continue; }
    if ( 'image/png' === $mime && self::animated( $source ) ) { ++$skipped; continue; }
    // Do not strip orientation from an unrotated full-resolution JPEG backup.
    if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
     $exif = @exif_read_data( $source );
     if ( ! empty( $exif['Orientation'] ) && 1 !== (int) $exif['Orientation'] ) { ++$skipped; continue; }
    }
    $hash = hash_file( 'sha256', $source );
    $key = sha1( _wp_relative_upload_path( $source ) );
    $record = $state['files'][ $key ] ?? null;
    if ( is_array( $record ) && ( $record['after_hash'] ?? '' ) === $hash ) {
     // Recover accounting if PHP stopped between the atomic replacement and its final metadata write.
     if ( 'prepared' === ( $record['state'] ?? '' ) ) { $state['files'][ $key ]['state'] = 'done'; update_post_meta( $id, self::META, $state ); }
     ++$skipped; continue;
    }
    $dimensions = @getimagesize( $source );
    $limit = ini_get( 'memory_limit' );
    if ( ! $dimensions || ( '-1' !== $limit && (float) $dimensions[0] * $dimensions[1] * 10 + 16 * MB_IN_BYTES > wp_convert_hr_to_bytes( $limit ) - memory_get_usage( true ) ) ) { $issues[] = 'حافظه برای این اندازه کافی نیست: ' . basename( $source ); continue; }
    Pixora_Diagnostics::stage( 'کاهش حجم با حفظ فرمت: ' . basename( $source ) );
    $editor = wp_get_image_editor( $source );
    if ( is_wp_error( $editor ) ) { $issues[] = $editor->get_error_message(); continue; }
    $quality = $editor->set_quality( Pixora::options()['quality'] );
    if ( is_wp_error( $quality ) ) { unset( $editor ); $issues[] = $quality->get_error_message(); continue; }
    $target = dirname( $source ) . '/.pixora-' . wp_generate_uuid4() . ( 'image/png' === $mime ? '.png' : '.jpg' );
    $reserved = @fopen( $target, 'x' );
    if ( ! $reserved ) { unset( $editor ); $issues[] = 'ایجاد فایل موقت ممکن نشد.'; continue; }
    fclose( $reserved ); $temporary[] = $target;
    add_filter( 'image_editor_output_format', array( __CLASS__, 'keep_format' ), PHP_INT_MAX );
    try { $saved = $editor->save( $target, $mime ); }
    finally { remove_filter( 'image_editor_output_format', array( __CLASS__, 'keep_format' ), PHP_INT_MAX ); unset( $editor ); }
    if ( is_wp_error( $saved ) || $saved['path'] !== $target || ! Pixora::safe_file( $target ) ) { $issues[] = 'ذخیرهٔ نسخهٔ هم‌فرمت ناموفق بود.'; continue; }
    $new_dimensions = @getimagesize( $target );
    if ( wp_get_image_mime( $target ) !== $mime || ! $new_dimensions || $dimensions[0] !== $new_dimensions[0] || $dimensions[1] !== $new_dimensions[1] ) { $issues[] = 'نسخهٔ جدید اعتبارسنجی نشد؛ فایل قبلی حفظ شد.'; continue; }
    clearstatcache( true, $target );
    $before = filesize( $source ); $after = filesize( $target );
    if ( $after >= $before ) { ++$skipped; continue; }
    if ( ! hash_equals( $hash, hash_file( 'sha256', $source ) ) ) { $issues[] = 'فایل هم‌زمان تغییر کرده است؛ جایگزین نشد.'; continue; }
    $previous = $state;
    $state['files'][ $key ] = array( 'path' => $source, 'after_hash' => hash_file( 'sha256', $target ), 'before' => $before, 'after' => $after, 'state' => 'prepared' );
    if ( ! update_post_meta( $id, self::META, $state ) ) { $state = $previous; $issues[] = 'ثبت اطلاعات جایگزینی ممکن نشد؛ فایل قبلی حفظ شد.'; continue; }
    $permissions = fileperms( $source ) & 0777;
    if ( ! @chmod( $target, $permissions ) || ! @rename( $target, $source ) ) {
     $state = $previous; update_post_meta( $id, self::META, $state ); $issues[] = 'جایگزینی اتمی توسط هاست پذیرفته نشد.'; continue;
    }
    clearstatcache( true, $source );
    $freed += max( 0, $before - filesize( $source ) );
    $state['files'][ $key ]['state'] = 'done';
    update_post_meta( $id, self::META, $state );
   }
   // Keep WordPress file-size metadata accurate; paths, MIME and attachment IDs never change.
   $current = wp_get_attachment_metadata( $id ); $current_file = get_attached_file( $id );
   if ( is_array( $current ) && Pixora::safe_file( $current_file ) ) {
    $current['filesize'] = filesize( $current_file );
    foreach ( $current['sizes'] ?? array() as $name => $size ) {
     $path = dirname( $current_file ) . '/' . $size['file'];
     if ( Pixora::safe_file( $path ) ) { $current['sizes'][ $name ]['filesize'] = filesize( $path ); }
    }
    wp_update_attachment_metadata( $id, $current );
   }
   if ( is_array( $backup ) && empty( $backup['reclaimed'] ) ) {
    $original = $backup['metadata'];
    if ( Pixora::safe_file( $backup['file'] ) ) { $original['filesize'] = filesize( $backup['file'] ); }
    foreach ( $original['sizes'] ?? array() as $name => $size ) {
     $path = dirname( $backup['file'] ) . '/' . $size['file'];
     if ( Pixora::safe_file( $path ) ) { $original['sizes'][ $name ]['filesize'] = filesize( $path ); }
    }
    $backup['metadata'] = $original; update_post_meta( $id, Pixora::BACKUP, $backup );
   }
   return array( 'freed' => $freed, 'skipped' => $skipped, 'issues' => $issues );
  } finally {
   foreach ( $temporary as $path ) { if ( Pixora::safe_file( $path ) ) { wp_delete_file( $path ); } }
   Pixora::release_lock( $lock );
  }
 }
 public static function total( $state ) {
  $total = 0;
  foreach ( $state['files'] ?? array() as $record ) {
   if ( 'done' === ( $record['state'] ?? '' ) || ( 'prepared' === ( $record['state'] ?? '' ) && Pixora::safe_file( $record['path'] ) && hash_equals( $record['after_hash'], hash_file( 'sha256', $record['path'] ) ) ) ) { $total += max( 0, $record['before'] - $record['after'] ); }
  }
  return $total;
 }
}
