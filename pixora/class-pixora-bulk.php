<?php
/** WordPress Media list actions open a reviewable job restricted to the selected attachment IDs. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Bulk {
 public static function modes() {
  return array( 'pixora_webp' => array( 'optimize', 'Pixora: تبدیل به WebP با حفظ اصل' ), 'pixora_webp_delete' => array( 'webp_delete', 'Pixora: تبدیل به WebP و حذف اصل پس از تأیید آدرس' ), 'pixora_smart' => array( 'optimize_reclaim', 'Pixora: کاهش حجم و آزادسازی هوشمند' ), 'pixora_remove_webp' => array( 'purge_webp', 'Pixora: حذف کپی‌های WebP قبلی' ) );
 }
 public static function init() {
  add_filter( 'bulk_actions-upload', array( __CLASS__, 'actions' ) );
  add_filter( 'handle_bulk_actions-upload', array( __CLASS__, 'handle' ), 10, 3 );
 }
 public static function actions( $actions ) {
  if ( current_user_can( 'manage_options' ) && current_user_can( 'upload_files' ) ) { foreach ( self::modes() as $key => $mode ) { $actions[$key] = $mode[1]; } }
  return $actions;
 }
 public static function handle( $redirect, $action, $ids ) {
  $modes = self::modes(); if ( ! isset( $modes[$action] ) ) { return $redirect; }
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_die( 'اجازهٔ اجرای عملیات Pixora را ندارید.', '', array( 'response' => 403 ) ); }
  check_admin_referer( 'bulk-media' );
  $ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $ids ) ) ) ); sort( $ids, SORT_NUMERIC );
  if ( ! $ids || count( $ids ) > 500 ) { wp_die( 'بین ۱ تا ۵۰۰ رسانه را انتخاب کنید.', '', array( 'response' => 400 ) ); }
  foreach ( $ids as $id ) { if ( 'attachment' !== get_post_type( $id ) || ! current_user_can( 'edit_post', $id ) ) { wp_die( 'به یکی از رسانه‌های انتخاب‌شده دسترسی ندارید.', '', array( 'response' => 403 ) ); } }
  $token = str_replace( '-', '', wp_generate_uuid4() );
  $job = array( 'ids' => $ids, 'mode' => $modes[$action][0], 'label' => $modes[$action][1] );
  if ( ! set_transient( 'pixora_job_' . get_current_user_id() . '_' . $token, $job, DAY_IN_SECONDS ) ) { wp_die( 'ثبت عملیات انتخابی ممکن نشد؛ تغییری روی تصاویر انجام نشد.' ); }
  return add_query_arg( array( 'page' => 'pixora', 'pixora_job' => $token ), admin_url( 'admin.php' ) );
 }
 public static function get( $token ) {
  if ( ! is_string( $token ) || ! preg_match( '/\A[a-f0-9]{32}\z/', $token ) ) { return new WP_Error( 'selection', 'شناسهٔ عملیات انتخابی نامعتبر است؛ دوباره از کتابخانه انتخاب کنید.' ); }
  $job = get_transient( 'pixora_job_' . get_current_user_id() . '_' . $token );
  if ( ! is_array( $job ) || empty( $job['ids'] ) || ! isset( $job['mode'], $job['label'] ) ) { return new WP_Error( 'selection', 'عملیات انتخابی منقضی شده یا متعلق به کاربر دیگری است؛ دوباره انتخاب کنید.' ); }
  $job['token'] = $token; return $job;
 }
}
