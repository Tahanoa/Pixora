<?php
/** Best-effort diagnostics for authenticated image operations. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Diagnostics {
 private static $active = false;
 private static $context = array();
 private static $reserve;
 private static $level = 0;
 public static function begin( $id, $mode ) {
  self::$active = true;
  self::$context = array( 'id' => (int) $id, 'mode' => $mode, 'stage' => 'شروع پردازش' );
  self::$reserve = str_repeat( 'r', 262144 );
  self::$level = ob_get_level();
  ob_start();
  register_shutdown_function( array( __CLASS__, 'shutdown' ) );
 }
 public static function stage( $stage ) { if ( self::$active ) { self::$context['stage'] = $stage; } }
 public static function message( $error ) {
  $raw = $error['message'] ?? '';
  if ( stripos( $raw, 'Allowed memory size' ) !== false ) { $reason = 'حافظهٔ PHP برای پردازش این تصویر کافی نیست.'; }
  elseif ( stripos( $raw, 'execution time' ) !== false ) { $reason = 'زمان مجاز اجرای PHP برای این تصویر تمام شد.'; }
  else { $reason = 'خطای داخلی PHP؛ پشتیبانی هاست باید error_log را بررسی کند.'; }
  return sprintf( 'تصویر #%d، مرحلهٔ «%s»: %s [PHP %s:%d]', self::$context['id'] ?? 0, self::$context['stage'] ?? 'نامشخص', $reason, basename( $error['file'] ?? 'unknown' ), (int) ( $error['line'] ?? 0 ) );
 }
 public static function finish() {
  self::$active = false; self::$reserve = null;
  while ( ob_get_level() > self::$level ) { ob_end_clean(); }
 }
 public static function shutdown() {
  if ( ! self::$active ) { return; }
  $error = error_get_last();
  if ( ! $error || ! in_array( $error['type'], array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR ), true ) ) { return; }
  self::$reserve = null;
  $message = self::message( $error );
  self::finish();
  // The web server may already have terminated the connection; this response is best effort.
  if ( ! headers_sent() ) { wp_send_json_error( array( 'message' => $message, 'id' => self::$context['id'] ), 500 ); }
 }
 public static function run( $id, $mode ) {
  self::begin( $id, $mode );
  try {
   if ( 'inplace' === $mode ) { return Pixora_Inplace::optimize( $id ); }
   if ( 'optimize_reclaim' === $mode ) { return Pixora_Workflow::combined( $id ); }
   if ( 'reclaim' === $mode ) { return Pixora_Workflow::reclaim( $id ); }
   if ( 'restore' === $mode ) { return Pixora::restore( $id ); }
   return Pixora::optimize( $id );
  } catch ( Throwable $error ) {
   error_log( 'Pixora attachment #' . (int) $id . ': ' . $error->getMessage() );
   return new WP_Error( 'php', self::message( array( 'message' => $error->getMessage(), 'file' => $error->getFile(), 'line' => $error->getLine() ) ) );
  } finally { self::finish(); }
 }
}
