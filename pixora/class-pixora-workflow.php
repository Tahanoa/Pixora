<?php
/** One-step optimization and optional background reclamation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Workflow {
 const QUEUE = '_pixora_cleanup_queue';
 const HOOK = 'pixora_cleanup_image';
 public static function can_fallback( $error ) {
  return is_wp_error( $error ) && in_array( $error->get_error_code(), array( 'preflight', 'host', 'probe', 'rules', 'url', 'redirect', 'support', 'files', 'no_saving' ), true );
 }
 public static function fallback( $id, $reason ) {
  $result = Pixora_Inplace::optimize( $id );
  if ( is_wp_error( $result ) ) { return $result; }
  if ( $result['freed'] <= 0 ) {
   $message = 'روش WebP روی مسیر فعلی قابل آزادسازی نبود؛ روش هم‌فرمت هم نسخهٔ کوچک‌تری نساخت. فضای آزادشده: ۰ بایت؛ هیچ کاهش یا حذفی گزارش نمی‌شود.';
   if ( $result['issues'] ) { $message .= ' ' . implode( '؛ ', array_slice( $result['issues'], 0, 3 ) ); }
   return new WP_Error( $result['issues'] ? 'fallback_failed' : 'no_gain', $message );
  }
  $result['method'] = 'inplace'; $result['remaining'] = 0;
  $result['message'] = 'مسیر WebP تأیید نشد؛ روش هم‌فرمت خودکار اجرا شد. ' . size_format( $result['freed'] ) . ' از حجم فایل‌های دیسک کم شد. فایل JPEG/PNG با نسخهٔ کوچک‌تر در همان آدرس جایگزین شد؛ WebPهای قبلی حفظ شدند.';
  if ( $result['issues'] ) { $result['message'] .= ' برخی اندازه‌ها تغییر نکردند: ' . implode( '؛ ', array_slice( $result['issues'], 0, 3 ) ); }
  update_post_meta( $id, self::QUEUE, array( 'state' => 'fallback', 'cause' => $reason->get_error_code(), 'message' => $result['message'] ) );
  return $result;
 }
 public static function reclaim( $id ) { return self::measure( $id, true ); }
 public static function combined( $id ) { return self::measure( $id, false ); }
 public static function strict( $id ) { return self::measure( $id, false, true ); }
 private static function measure( $id, $existing_only, $strict = false ) {
  $before = Pixora_Gallery::files( $id );
  $result = $existing_only ? self::reclaim_internal( $id ) : self::combined_internal( $id, $strict );
  if ( is_wp_error( $result ) ) { return $result; }
  $after = Pixora_Gallery::files( $id );
  $net = $before['bytes'] - $after['bytes'];
  if ( $net <= 0 ) {
   return new WP_Error( $net < 0 ? 'disk_increased' : 'no_gain', $net < 0 ? 'فضای خالص کم نشد؛ فایل‌های لازم برای حفظ لینک‌ها باقی ماندند و حجم فایل‌ها ' . size_format( -$net ) . ' بیشتر شد. حذف اجباری انجام نشد.' : 'فضای خالص آزادشده: ۰ بایت؛ کاهش حجمی رخ نداد و موفقیت گزارش نمی‌شود.' );
  }
  $result['removed_bytes'] = $result['freed']; $result['freed'] = $net;
  $result['message'] = ( 'inplace' === $result['method'] ? 'آزادسازی با WebP انجام نشد؛ JPEG/PNG خودکار با نسخهٔ هم‌فرمت کوچک‌تر در همان آدرس جایگزین شد. WebPهای قبلی حفظ شدند. ' : 'فایل‌های اصلی حذف و نسخهٔ WebP نگهداری شد. ' ) . 'کاهش خالص حجم فایل‌های دیسک: ' . size_format( $net ) . '.';
  if ( ! empty( $result['issues'] ) ) { $result['message'] .= ' برخی اندازه‌ها تغییر نکردند: ' . implode( '؛ ', array_slice( $result['issues'], 0, 3 ) ); }
  if ( ! empty( $result['remaining'] ) ) { $result['message'] .= ' ' . $result['remaining'] . ' فایل حذف نشد.'; }
  if ( 'inplace' === $result['method'] ) { update_post_meta( $id, self::QUEUE, array( 'state' => 'fallback', 'message' => $result['message'] ) ); }
  return $result;
 }
 private static function reclaim_internal( $id ) {
  $cleaned = Pixora_Storage::reclaim( $id );
  if ( self::can_fallback( $cleaned ) ) { return self::fallback( $id, $cleaned ); }
  if ( ! is_wp_error( $cleaned ) ) {
   $cleaned['method'] = 'webp';
   $cleaned['message'] = size_format( $cleaned['freed'] ) . ' با حذف فایل‌های اصلی آزاد شد.';
   if ( $cleaned['remaining'] ) { $cleaned['message'] .= ' ' . $cleaned['remaining'] . ' فایل حذف نشد؛ نیازمند بررسی است.'; }
  }
  return $cleaned;
 }
 private static function combined_internal( $id, $strict = false ) {
  $backup = get_post_meta( $id, Pixora::BACKUP, true );
  if ( ! $backup ) {
   $check = Pixora_Storage::preflight( get_attached_file( $id ) );
   if ( ! $strict && self::can_fallback( $check ) ) { return self::fallback( $id, $check ); }
   if ( is_wp_error( $check ) ) { return $check; }
   $optimized = Pixora::optimize( $id );
   if ( ! $strict && self::can_fallback( $optimized ) ) { return self::fallback( $id, $optimized ); }
   if ( is_wp_error( $optimized ) ) { return $optimized; }
  }
  if ( $strict ) { $result = Pixora_Storage::reclaim( $id ); if ( ! is_wp_error( $result ) ) { $result['method'] = 'webp'; } return $result; }
  return self::reclaim_internal( $id );
 }
 public static function should_pause( $result ) {
  if ( ! is_wp_error( $result ) ) { return false; }
  $code = $result->get_error_code();
  if ( 'cleanup_pending' === $code ) {
   $data = $result->get_error_data();
   $code = is_array( $data ) ? ( $data['cause'] ?? '' ) : '';
  }
  return in_array( $code, array( 'preflight', 'probe', 'host', 'rules', 'url', 'support', 'disk_increased' ), true );
 }
 public static function enqueue( $id ) {
  if ( ! Pixora::options()['auto_cleanup'] || ! get_post_meta( $id, Pixora::BACKUP, true ) ) { return; }
  if ( ! wp_next_scheduled( self::HOOK, array( (int) $id ) ) ) {
   $scheduled = wp_schedule_single_event( time() + 10, self::HOOK, array( (int) $id ), true );
   update_post_meta( $id, self::QUEUE, array( 'state' => is_wp_error( $scheduled ) || ! $scheduled ? 'failed' : 'pending', 'attempts' => 0, 'message' => is_wp_error( $scheduled ) || ! $scheduled ? 'زمان‌بندی انجام نشد؛ آزادسازی را از پنل اجرا کنید.' : 'در انتظار اجرای زمان‌بندی وردپرس' ) );
  }
 }
 public static function process( $id ) {
  if ( ! Pixora::options()['auto_cleanup'] || 'attachment' !== get_post_type( $id ) ) { return; }
  $backup = get_post_meta( $id, Pixora::BACKUP, true );
  if ( ! $backup || ! empty( $backup['reclaimed'] ) ) { return; }
  $queue = get_post_meta( $id, self::QUEUE, true );
  $attempts = isset( $queue['attempts'] ) ? (int) $queue['attempts'] + 1 : 1;
  $result = Pixora_Diagnostics::run( $id, 'reclaim' );
  update_post_meta( $id, self::QUEUE, array( 'state' => is_wp_error( $result ) ? ( 'no_gain' === $result->get_error_code() ? 'unchanged' : 'failed' ) : ( ( $result['method'] ?? '' ) === 'inplace' ? 'fallback' : 'done' ), 'attempts' => $attempts, 'message' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['message'] ?? 'آزادسازی انجام شد.' ) ) );
  // No endless retries or delayed deletion after a failure; manual review is available in Media.
 }
 public static function deactivate() { wp_unschedule_hook( self::HOOK ); }
}
