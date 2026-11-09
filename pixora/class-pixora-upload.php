<?php
/** Convert a newly uploaded file before WordPress publishes its URL or creates sizes. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Upload {
 public static function browser_settings( $settings ) {
  if ( Pixora::options()['upload_webp_only'] && Pixora::options()['browser_upload'] ) {
   if ( ! isset( $settings['filters'] ) || ! is_array( $settings['filters'] ) ) { $settings['filters'] = array(); }
   // Permit a larger local source, but keep the host's limit for encoded uploads.
   $limit = $settings['filters']['max_file_size'] ?? wp_max_upload_size();
   $settings['filters']['pixora_webp'] = array( 'max_file_size' => $limit );
   $settings['filters']['max_file_size'] = 64 * 1024 * 1024;
  }
  return $settings;
 }
 public static function browser_assets() {
  if ( ! current_user_can( 'upload_files' ) || ! Pixora::options()['upload_webp_only'] || ! Pixora::options()['browser_upload'] ) { return; }
  wp_enqueue_style( 'pixora-browser', plugins_url( 'assets/browser-upload.css', __FILE__ ), array(), '0.9.0' );
  wp_enqueue_script( 'pixora-codec', plugins_url( 'assets/browser-codec.js', __FILE__ ), array(), '0.9.0', true );
  wp_enqueue_script( 'pixora-browser', plugins_url( 'assets/browser-upload.js', __FILE__ ), array( 'plupload', 'pixora-codec' ), '0.9.0', true );
  wp_localize_script( 'pixora-browser', 'pixoraBrowser', array( 'enabled' => true, 'quality' => Pixora::options()['quality'], 'worker' => plugins_url( 'assets/browser-worker.js', __FILE__ ) . '?ver=0.9.0' ) );
 }

 public static function handle( $upload, $context = 'upload' ) {
  if ( ! Pixora::options()['upload_webp_only'] || ! empty( $upload['error'] ) || empty( $upload['file'] ) ) { return $upload; }
  $source = $upload['file'];
  $mime = wp_get_image_mime( $source );
  if ( ! in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) { return $upload; }
  if ( ! Pixora::safe_file( $source ) || is_link( $source ) ) { return array( 'error' => 'Pixora: مسیر فایل بارگذاری‌شده معتبر نیست.' ); }
  $target = null; $committed = false;
  try {
   if ( 'image/png' === $mime && Pixora_Gallery::animated( $source ) ) { throw new RuntimeException( 'PNG متحرک برای جلوگیری از حذف حرکت تبدیل نمی‌شود؛ آن را به WebP متحرک تبدیل و سپس بارگذاری کنید.' ); }
   if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) { throw new RuntimeException( 'هاست از ساخت WebP پشتیبانی نمی‌کند؛ بارگذاری JPEG/PNG انجام نشد.' ); }
   if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'image' ); }
   $dimensions = @getimagesize( $source );
   if ( ! $dimensions ) { throw new RuntimeException( 'خواندن ابعاد تصویر ممکن نیست.' ); }
   $limit = ini_get( 'memory_limit' );
   if ( '-1' !== $limit && $dimensions[0] * $dimensions[1] * 10 + 16 * 1048576 > wp_convert_hr_to_bytes( $limit ) - memory_get_usage( true ) ) { throw new RuntimeException( 'حافظهٔ هاست برای تبدیل این تصویر کافی نیست؛ تصویر کوچک‌تری بارگذاری کنید.' ); }
   $editor = wp_get_image_editor( $source );
   if ( is_wp_error( $editor ) ) { throw new RuntimeException( $editor->get_error_message() ); }
   $quality = $editor->set_quality( Pixora::options()['quality'] );
   if ( is_wp_error( $quality ) ) { throw new RuntimeException( $quality->get_error_message() ); }
   if ( method_exists( $editor, 'maybe_exif_rotate' ) ) {
    $rotated = $editor->maybe_exif_rotate();
    if ( is_wp_error( $rotated ) ) { throw new RuntimeException( $rotated->get_error_message() ); }
   }
   $expected = $editor->get_size();
   $dir = dirname( $source );
   $name = pathinfo( $source, PATHINFO_FILENAME ) . '.webp';
   // Reserve our own output; never overwrite another upload with the same stem.
   for ( $attempt = 0; $attempt < 10; ++$attempt ) {
    $candidate = $dir . '/' . wp_unique_filename( $dir, $name );
    $reservation = @fopen( $candidate, 'x' );
    if ( $reservation ) { fclose( $reservation ); $target = $candidate; break; }
   }
   if ( ! $target ) { throw new RuntimeException( 'ایجاد فایل WebP ممکن نیست.' ); }
   // Keep WebP for the main file even if another output-format filter is installed.
   add_filter( 'image_editor_output_format', array( __CLASS__, 'webp_format' ), PHP_INT_MAX );
   try { $saved = $editor->save( $target, 'image/webp' ); }
   finally { remove_filter( 'image_editor_output_format', array( __CLASS__, 'webp_format' ), PHP_INT_MAX ); }
   unset( $editor );
   clearstatcache( true, $target );
   $actual = @getimagesize( $target );
   if ( is_wp_error( $saved ) || ( $saved['path'] ?? '' ) !== $target || ! $actual || 'image/webp' !== wp_get_image_mime( $target ) || $actual[0] !== $expected['width'] || $actual[1] !== $expected['height'] || ! filesize( $target ) ) { throw new RuntimeException( 'اعتبارسنجی فایل WebP ناموفق بود؛ رسانه ثبت نشد.' ); }
   wp_delete_file( $source );
   clearstatcache( true, $source );
   if ( file_exists( $source ) ) { throw new RuntimeException( 'حذف فایل اولیه ممکن نشد؛ بارگذاری فقط WebP تکمیل نشد.' ); }
   $upload['file'] = $target;
   $upload['url'] = trailingslashit( dirname( $upload['url'] ) ) . wp_basename( $target );
   $upload['type'] = 'image/webp';
   $committed = true;
   return $upload;
  } catch ( Throwable $error ) {
   return array( 'error' => 'Pixora: ' . $error->getMessage() );
  } finally {
   if ( ! $committed ) {
    if ( $target && Pixora::safe_file( $target ) ) { wp_delete_file( $target ); }
    // The upload has not been published or attached. Do not leave a rejected JPEG/PNG behind.
    if ( Pixora::safe_file( $source ) ) { wp_delete_file( $source ); }
   }
  }
 }
 public static function webp_format( $formats ) { $formats['image/webp'] = 'image/webp'; return $formats; }
}
