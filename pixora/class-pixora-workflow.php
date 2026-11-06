<?php
/** One-step optimization and optional background reclamation. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Workflow {
 const QUEUE = '_pixora_cleanup_queue';
 const HOOK = 'pixora_cleanup_image';
 public static function combined( $id ) {
  $backup = get_post_meta( $id, Pixora::BACKUP, true );
  if ( ! $backup ) {
   $check = Pixora_Storage::preflight( get_attached_file( $id ) );
   if ( is_wp_error( $check ) ) { return $check; }
   $optimized = Pixora::optimize( $id );
   if ( is_wp_error( $optimized ) ) { return $optimized; }
  }
  $cleaned = Pixora_Storage::reclaim( $id );
  if ( is_wp_error( $cleaned ) ) {
   return new WP_Error( 'cleanup_pending', 'WebP آماده است، اما اصل تصویر حفظ شد: ' . $cleaned->get_error_message(), array( 'cause' => $cleaned->get_error_code() ) );
  }
  return $cleaned;
 }
 public static function should_pause( $result ) {
  if ( ! is_wp_error( $result ) ) { return false; }
  $code = $result->get_error_code();
  if ( 'cleanup_pending' === $code ) {
   $data = $result->get_error_data();
   $code = is_array( $data ) ? ( $data['cause'] ?? '' ) : '';
  }
  return in_array( $code, array( 'preflight', 'probe', 'host', 'rules', 'url', 'support' ), true );
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
  update_post_meta( $id, self::QUEUE, array( 'state' => is_wp_error( $result ) ? 'failed' : 'done', 'attempts' => $attempts, 'message' => is_wp_error( $result ) ? $result->get_error_message() : 'آزادسازی انجام شد.' ) );
  // No endless retries or delayed deletion after a failure; manual review is available in Media.
 }
 public static function deactivate() { wp_unschedule_hook( self::HOOK ); }
}
