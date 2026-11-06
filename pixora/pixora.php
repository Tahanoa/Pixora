<?php
/**
 * Plugin Name: Pixora
 * Description: فشرده‌سازی تصاویر و تبدیل به WebP با حفظ فایل اصلی و امکان بازگردانی.
 * Version: 0.4.2
 * Author: tahanoa
 * Author URI: https://github.com/Tahanoa
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: pixora
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-pixora-storage.php';
require_once __DIR__ . '/class-pixora-diagnostics.php';
require_once __DIR__ . '/class-pixora-workflow.php';

final class Pixora {
 const BACKUP = '_pixora_original';
 const OPTION = 'pixora_settings';

 public static function init() {
  add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
  add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
  add_action( 'admin_init', array( __CLASS__, 'settings' ) );
  add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
  add_action( 'wp_ajax_pixora_batch', array( __CLASS__, 'batch' ) );
  add_action( 'wp_ajax_pixora_optimize', array( __CLASS__, 'single' ) );
  add_filter( 'media_row_actions', array( __CLASS__, 'media_actions' ), 10, 2 );
  add_filter( 'attachment_fields_to_edit', array( __CLASS__, 'media_fields' ), 10, 2 );
  add_action( Pixora_Workflow::HOOK, array( 'Pixora_Workflow', 'process' ) );
  add_action( 'delete_attachment', array( __CLASS__, 'cleanup' ) );
 }
 // OS file locks are released even when PHP is killed or reaches its execution limit.
 public static function acquire_lock( $id ) {
  $legacy = 'pixora_lock_' . absint( $id );
  $started = get_option( $legacy, false );
  if ( false !== $started ) {
   // Allow an in-flight request from an older plugin version time to finish.
   if ( ! is_numeric( $started ) || (int) $started > time() - HOUR_IN_SECONDS ) {
    return new WP_Error( 'busy', 'یک پردازش قدیمی هنوز ممکن است فعال باشد؛ حداکثر یک ساعت بعد دوباره تلاش کنید.' );
   }
   delete_option( $legacy );
  }
  $uploads = wp_get_upload_dir();
  $base = realpath( $uploads['basedir'] );
  if ( ! $base || ! is_writable( $base ) ) { return new WP_Error( 'lock', 'پوشهٔ بارگذاری برای ایجاد قفل پردازش قابل نوشتن نیست.' ); }
  $path = $base . '/.pixora-' . absint( $id ) . '.lock';
  if ( is_link( $path ) ) { return new WP_Error( 'lock', 'مسیر قفل پردازش نامعتبر است.' ); }
  $handle = fopen( $path, 'c' );
  if ( ! $handle ) { return new WP_Error( 'lock', 'ایجاد قفل پردازش ممکن نشد.' ); }
  if ( ! flock( $handle, LOCK_EX | LOCK_NB ) ) { fclose( $handle ); return new WP_Error( 'busy', 'تصویر واقعاً در حال پردازش است؛ کمی بعد دوباره تلاش کنید.' ); }
  // Keep the empty file: unlinking a lock file can let two processes lock different inodes.
  return $handle;
 }
 public static function release_lock( $handle ) {
  if ( is_resource( $handle ) ) { flock( $handle, LOCK_UN ); fclose( $handle ); }
 }
 public static function options() {
  return wp_parse_args( get_option( self::OPTION, array() ), array( 'quality' => 82, 'automatic' => 1, 'auto_cleanup' => 0 ) );
 }
 public static function sanitize( $input ) {
  $input = is_array( $input ) ? $input : array();
  $cleanup = ! empty( $input['automatic'] ) && ! empty( $input['auto_cleanup'] ) && ! empty( $input['cleanup_consent'] ) && Pixora_Storage::host()['supported'];
  if ( ! empty( $input['auto_cleanup'] ) && ! $cleanup && function_exists( 'add_settings_error' ) ) { add_settings_error( self::OPTION, 'cleanup_consent', 'آزادسازی خودکار فعال نشد؛ فشرده‌سازی خودکار، تأیید بکاپ و هاست سازگار لازم است.', 'warning' ); }
  return array( 'auto_cleanup' => $cleanup ? 1 : 0, 'quality' => max( 1, min( 100, absint( $input['quality'] ?? 82 ) ) ), 'automatic' => empty( $input['automatic'] ) ? 0 : 1 );
 }
 public static function settings() {
  register_setting( 'pixora', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
 }
 public static function on_upload( $metadata, $id ) {
  if ( self::options()['automatic'] && ! get_post_meta( $id, self::BACKUP, true ) ) {
   if ( self::options()['auto_cleanup'] ) {
    $check = Pixora_Storage::preflight( get_attached_file( $id ) );
    if ( is_wp_error( $check ) ) {
     update_post_meta( $id, Pixora_Workflow::QUEUE, array( 'state' => 'failed', 'message' => $check->get_error_message() ) );
     return $metadata;
    }
   }
   $result = self::optimize( $id, $metadata );
   if ( ! is_wp_error( $result ) ) { Pixora_Workflow::enqueue( $id ); return $result; }
  }
  return $metadata;
 }
 // Only operate on actual files within the uploads directory, including for cleanup.
 public static function safe_file( $path ) {
  $uploads = wp_get_upload_dir();
  $base = realpath( $uploads['basedir'] );
  $real = realpath( $path );
  return $base && $real && is_file( $real ) && 0 === strpos( $real, $base . DIRECTORY_SEPARATOR );
 }
 public static function optimize( $id, $metadata = null ) {
  if ( get_post_meta( $id, self::BACKUP, true ) ) { return new WP_Error( 'done', __( 'این تصویر قبلاً با Pixora فشرده شده است.', 'pixora' ) ); }
  if ( ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
   return new WP_Error( 'format', __( 'فقط تصاویر JPEG و PNG قابل فشرده‌سازی هستند.', 'pixora' ) );
  }
  if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
   return new WP_Error( 'support', __( 'سرور شما از ساخت تصاویر WebP پشتیبانی نمی‌کند.', 'pixora' ) );
  }
  // A process-owned lock prevents simultaneous upload/batch requests for this attachment.
  $lock = self::acquire_lock( $id );
  if ( is_wp_error( $lock ) ) { return $lock; }
  $created = array();
  try {
   $metadata = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $id );
   $file = get_attached_file( $id );
   if ( ! self::safe_file( $file ) || empty( $metadata['file'] ) ) { return new WP_Error( 'missing', __( 'فایل اصلی یا اطلاعات تصویر پیدا نشد.', 'pixora' ) ); }
   // Animated PNGs must not be flattened by a server image editor.
   if ( 'image/png' === get_post_mime_type( $id ) ) {
    $handle = fopen( $file, 'rb' );
    if ( ! $handle ) { return new WP_Error( 'read', __( 'خواندن تصویر ممکن نیست.', 'pixora' ) ); }
    $animated = false;
    while ( ! feof( $handle ) ) {
     $header = fread( $handle, 8 );
     if ( 8 !== strlen( $header ) ) { break; }
     if ( "\x89PNG\r\n\x1a\n" === $header ) { continue; }
     $chunk = unpack( 'Nlength/a4type', $header );
     if ( 'acTL' === $chunk['type'] ) { $animated = true; break; }
     if ( 'IDAT' === $chunk['type'] || 'IEND' === $chunk['type'] ) { break; }
     if ( 0 !== fseek( $handle, $chunk['length'] + 4, SEEK_CUR ) ) { break; }
    }
    fclose( $handle );
    if ( $animated ) { return new WP_Error( 'animated', __( 'تصاویر PNG متحرک بدون تغییر حفظ می‌شوند.', 'pixora' ) ); }
   }
   if ( function_exists( 'wp_raise_memory_limit' ) ) { wp_raise_memory_limit( 'image' ); }
   $sources = array( $file );
   foreach ( $metadata['sizes'] ?? array() as $size ) { $sources[] = dirname( $file ) . '/' . $size['file']; }
   if ( ! empty( $metadata['original_image'] ) ) { $sources[] = dirname( $file ) . '/' . $metadata['original_image']; }
   $sources = array_values( array_unique( $sources ) );
   $map = array(); $before = 0; $after = 0;
   foreach ( $sources as $source ) {
    if ( ! self::safe_file( $source ) ) { return new WP_Error( 'missing', __( 'یکی از اندازه‌های تصویر موجود نیست یا خارج از پوشهٔ بارگذاری است.', 'pixora' ) ); }
    Pixora_Diagnostics::stage( 'خواندن ' . basename( $source ) );
    $dimensions = @getimagesize( $source );
    $limit = ini_get( 'memory_limit' );
    if ( $dimensions && '-1' !== $limit && function_exists( 'wp_convert_hr_to_bytes' ) ) {
     $needed = (float) $dimensions[0] * $dimensions[1] * 10 + 16 * 1048576;
     if ( $needed > wp_convert_hr_to_bytes( $limit ) - memory_get_usage( true ) ) {
      return new WP_Error( 'memory', 'حافظهٔ آزاد PHP برای این تصویر کافی نیست؛ تصویر اصلی حفظ شد. محدودیت حافظهٔ PHP را با پشتیبانی هاست بررسی کنید.' );
     }
    }
    $editor = wp_get_image_editor( $source );
    if ( is_wp_error( $editor ) ) { return $editor; }
    $quality = $editor->set_quality( self::options()['quality'] );
    if ( is_wp_error( $quality ) ) { return $quality; }
    $target = dirname( $source ) . '/' . wp_unique_filename( dirname( $source ), pathinfo( $source, PATHINFO_FILENAME ) . '-pixora.webp' );
    Pixora_Diagnostics::stage( 'ساخت WebP برای ' . basename( $source ) );
    $saved = $editor->save( $target, 'image/webp' );
    // Release the decoded image before allocating the next size (not after it loads).
    unset( $editor );
    if ( is_wp_error( $saved ) ) { return $saved; }
    $created[] = $saved['path'];
    if ( ! self::safe_file( $saved['path'] ) || 'image/webp' !== $saved['mime-type'] ) { return new WP_Error( 'encode', __( 'تبدیل تصویر به WebP ناموفق بود.', 'pixora' ) ); }
    $before += filesize( $source ); $after += filesize( $saved['path'] );
    $map[ $source ] = $saved;
   }
   if ( $after >= $before ) { return new WP_Error( 'no_saving', __( 'تصاویر اصلی کم‌حجم‌تر هستند و بدون تغییر حفظ شدند.', 'pixora' ) ); }
   $backup = array( 'file' => $file, 'metadata' => $metadata, 'mime' => get_post_mime_type( $id ), 'generated' => $created, 'sources' => $sources, 'before' => $before, 'after' => $after );
   if ( ! add_post_meta( $id, self::BACKUP, $backup, true ) ) { return new WP_Error( 'backup', __( 'ذخیرهٔ اطلاعات بازگردانی ممکن نشد.', 'pixora' ) ); }
   $new = $metadata;
   $new['file'] = _wp_relative_upload_path( $map[ $file ]['path'] );
   $new['filesize'] = filesize( $map[ $file ]['path'] );
   if ( ! empty( $metadata['original_image'] ) ) { $new['original_image'] = basename( $map[ dirname( $file ) . '/' . $metadata['original_image'] ]['path'] ); }
   foreach ( $new['sizes'] ?? array() as $key => $size ) {
    $entry = $map[ dirname( $file ) . '/' . $size['file'] ];
    $new['sizes'][ $key ]['file'] = basename( $entry['path'] );
    $new['sizes'][ $key ]['mime-type'] = 'image/webp';
    $new['sizes'][ $key ]['filesize'] = filesize( $entry['path'] );
   }
   Pixora_Diagnostics::stage( 'ثبت اطلاعات تصویر' );
   $updated_file = update_attached_file( $id, $map[ $file ]['path'] );
   $updated_post = wp_update_post( array( 'ID' => $id, 'post_mime_type' => 'image/webp' ), true );
   // Persist here too: bulk optimization does not run inside the metadata filter.
   $updated_meta = wp_update_attachment_metadata( $id, $new );
   if ( ! $updated_file || is_wp_error( $updated_post ) || ! $updated_post || ! $updated_meta ) {
    update_attached_file( $id, $backup['file'] );
    wp_update_post( array( 'ID' => $id, 'post_mime_type' => $backup['mime'] ) );
    wp_update_attachment_metadata( $id, $backup['metadata'] );
    // Keep files and recovery metadata if rollback could not be persisted.
    if ( get_attached_file( $id ) !== $backup['file'] || get_post_mime_type( $id ) !== $backup['mime'] || wp_get_attachment_metadata( $id ) !== $backup['metadata'] ) {
     $created = array();
    } else { delete_post_meta( $id, self::BACKUP ); }
    return new WP_Error( 'persist', __( 'به‌روزرسانی اطلاعات تصویر ممکن نشد؛ دسترسی پایگاه داده را بررسی کنید.', 'pixora' ) );
   }
   $created = array();
   return $new;
  } finally {
   foreach ( $created as $path ) { if ( self::safe_file( $path ) ) { wp_delete_file( $path ); } }
   self::release_lock( $lock );
  }
 }
 public static function restore( $id ) {
  $lock = self::acquire_lock( $id );
  if ( is_wp_error( $lock ) ) { return $lock; }
  try {
   $backup = get_post_meta( $id, self::BACKUP, true );
   if ( ! empty( $backup['reclaimed'] ) ) { return new WP_Error( 'reclaimed', 'اصل تصاویر در حالت آزادسازی حذف شده‌اند؛ بازگردانی فقط از بکاپ خارج از افزونه ممکن است.' ); }
   if ( ! $backup || ! self::safe_file( $backup['file'] ) ) { return new WP_Error( 'missing', __( 'فایل اصلی برای بازگردانی در دسترس نیست.', 'pixora' ) ); }
   update_attached_file( $id, $backup['file'] );
   wp_update_post( array( 'ID' => $id, 'post_mime_type' => $backup['mime'] ) );
   wp_update_attachment_metadata( $id, $backup['metadata'] );
   if ( get_attached_file( $id ) !== $backup['file'] || get_post_mime_type( $id ) !== $backup['mime'] || wp_get_attachment_metadata( $id ) !== $backup['metadata'] ) {
    return new WP_Error( 'persist', __( 'ذخیرهٔ بازگردانی ممکن نشد؛ اطلاعات بازیابی حفظ شده است.', 'pixora' ) );
   }
   // Keep generated files: published content may already contain these WebP URLs.
   delete_post_meta( $id, self::BACKUP );
   return true;
  } finally { self::release_lock( $lock ); }
 }
 public static function cleanup( $id ) {
  $backup = get_post_meta( $id, self::BACKUP, true );
  if ( ! $backup ) { return; }
  $paths = array_merge( $backup['sources'] ?? array( $backup['file'] ), $backup['generated'] );
  foreach ( $backup['metadata']['sizes'] ?? array() as $size ) { $paths[] = dirname( $backup['file'] ) . '/' . $size['file']; }
  foreach ( array_unique( $paths ) as $path ) { if ( self::safe_file( $path ) ) { wp_delete_file( $path ); } }
 }
 public static function batch() {
  check_ajax_referer( 'pixora_batch', 'nonce' );
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => __( 'اجازهٔ انجام این کار را ندارید.', 'pixora' ) ), 403 ); }
  global $wpdb;
  $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? $_POST['mode'] : 'optimize';
  if ( ! in_array( $mode, array( 'optimize', 'restore', 'reclaim', 'optimize_reclaim' ), true ) ) { wp_send_json_error( array( 'message' => 'عملیات نامعتبر است.' ), 400 ); }
  if ( in_array( $mode, array( 'reclaim', 'optimize_reclaim' ), true ) && ( ! isset( $_POST['consent'] ) || 'delete-originals' !== $_POST['consent'] ) ) { wp_send_json_error( array( 'message' => 'برای حذف اصل تصاویر، تأیید آگاهانه لازم است.' ), 400 ); }
  $restore = 'restore' === $mode;
  $cursor = absint( $_POST['cursor'] ?? 0 );
  // Query only attachments relevant to the requested operation, not the entire library.
  $exists = 'optimize' === $mode ? 'NOT EXISTS' : 'EXISTS';
  $condition = 'optimize_reclaim' === $mode ? '' : "AND $exists (SELECT 1 FROM {$wpdb->postmeta} pm WHERE pm.post_id = p.ID AND pm.meta_key = %s)";
  $mime = 'optimize' === $mode ? "('image/jpeg','image/png')" : "('image/jpeg','image/png','image/webp')";
  $sql = "SELECT p.ID FROM {$wpdb->posts} p WHERE p.post_type = 'attachment' AND p.ID > %d AND p.post_mime_type IN $mime $condition ORDER BY p.ID ASC LIMIT 50";
  $query = 'optimize_reclaim' === $mode ? $wpdb->prepare( $sql, $cursor ) : $wpdb->prepare( $sql, $cursor, self::BACKUP );
  $ids = $wpdb->get_col( $query );
  if ( ! $ids ) { wp_send_json_success( array( 'done' => true ) ); }
  $id = 0;
  foreach ( $ids as $candidate ) {
   $data = get_post_meta( (int) $candidate, self::BACKUP, true );
   if ( 'optimize' === $mode || ( 'optimize_reclaim' === $mode && ! $data && in_array( get_post_mime_type( $candidate ), array( 'image/jpeg', 'image/png' ), true ) ) || ( is_array( $data ) && empty( $data['reclaimed'] ) ) ) { $id = (int) $candidate; break; }
  }
  if ( ! $id ) { wp_send_json_success( array( 'done' => false, 'scanning' => true, 'cursor' => (int) end( $ids ) ) ); }
  if ( ! current_user_can( 'edit_post', $id ) ) { $result = new WP_Error( 'access', __( 'اجازهٔ ویرایش این رسانه را ندارید.', 'pixora' ) ); }
  else { $result = Pixora_Diagnostics::run( $id, $mode ); }
  $backup = get_post_meta( $id, self::BACKUP, true );
  $status = 'processed';
  if ( is_wp_error( $result ) ) {
   $code = $result->get_error_code();
   $status = 'cleanup_pending' === $code ? 'partial' : ( in_array( $code, array( 'done', 'format', 'no_saving', 'state', 'animated' ), true ) ? 'skipped' : 'failed' );
  }
  wp_send_json_success( array( 'done' => false, 'cursor' => $id, 'halt' => Pixora_Workflow::should_pause( $result ), 'status' => $status, 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'عملیات با موفقیت انجام شد.', 'pixora' ), 'saved' => ! is_wp_error( $result ) && 'optimize' === $mode && $backup ? max( 0, $backup['before'] - $backup['after'] ) : 0, 'freed' => ! is_wp_error( $result ) && in_array( $mode, array( 'reclaim', 'optimize_reclaim' ), true ) ? $result['freed'] : 0, 'remaining' => ! is_wp_error( $result ) && in_array( $mode, array( 'reclaim', 'optimize_reclaim' ), true ) ? $result['remaining'] : 0 ) );
 }
 public static function media_button( $post ) {
  if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) || ! in_array( $post->post_mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return ''; }
  $backup = get_post_meta( $post->ID, self::BACKUP, true );
  $html = '<span class="pixora-media-tools">';
  if ( $backup ) {
   $html .= '<span class="pixora-media-done">✓ WebP آماده · ' . esc_html( size_format( max( 0, $backup['before'] - $backup['after'] ) ) ) . ' کاهش حجم</span>';
   if ( ! empty( $backup['reclaimed'] ) ) {
    $html .= '<br><span>فضای آزادشده: ' . esc_html( size_format( $backup['disk_freed'] ?? 0 ) ) . '</span>';
    if ( ! empty( $backup['remaining_originals'] ) ) { $html .= '<br><span>بخشی از فایل‌های اصلی حذف نشده؛ نیازمند بررسی پشتیبانی هاست.</span>'; }
   } else {
    $html .= '<br><span>اصل تصویر هنوز موجود است.</span>';
    if ( current_user_can( 'manage_options' ) ) {
     $html .= self::media_control( $post->ID, 'reclaim', 'حذف اصل و آزادسازی فضا' );
     $html .= self::media_control( $post->ID, 'restore', 'بازگردانی اصل تصویر' );
    }
   }
  } elseif ( 'image/webp' !== $post->post_mime_type ) {
   $html .= self::media_control( $post->ID, 'optimize', 'فشرده‌سازی با Pixora' );
   if ( current_user_can( 'manage_options' ) ) { $html .= self::media_control( $post->ID, 'optimize_reclaim', 'فشرده‌سازی و حذف اصل' ); }
  } else { return ''; }
  $queue = get_post_meta( $post->ID, Pixora_Workflow::QUEUE, true );
  if ( $queue && ! empty( $queue['message'] ) && empty( $backup['reclaimed'] ) ) { $html .= '<br><small>' . esc_html( $queue['message'] ) . '</small>'; }
  return $html . '<span class="pixora-media-result" role="status" aria-live="polite"></span></span>';
 }
 public static function media_control( $id, $mode, $label ) {
  return '<button type="button" class="button button-small pixora-media-button" data-pixora-id="' . esc_attr( $id ) . '" data-pixora-mode="' . esc_attr( $mode ) . '">' . esc_html( $label ) . '</button> ';
 }
 public static function media_actions( $actions, $post ) {
  $button = self::media_button( $post );
  if ( $button ) { $actions['pixora'] = $button; }
  return $actions;
 }
 public static function media_fields( $fields, $post ) {
  $button = self::media_button( $post );
  if ( $button ) { $fields['pixora'] = array( 'label' => 'Pixora', 'input' => 'html', 'html' => $button ); }
  return $fields;
 }
 public static function single() {
  check_ajax_referer( 'pixora_batch', 'nonce' );
  $id = absint( $_POST['id'] ?? 0 );
  if ( ! $id || 'attachment' !== get_post_type( $id ) || ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $id ) ) {
   wp_send_json_error( array( 'message' => 'اجازهٔ ویرایش این رسانه را ندارید.' ), 403 );
  }
  $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? $_POST['mode'] : 'optimize';
  if ( ! in_array( $mode, array( 'optimize', 'reclaim', 'restore', 'optimize_reclaim' ), true ) ) { wp_send_json_error( array( 'message' => 'عملیات نامعتبر است.' ), 400 ); }
  if ( 'optimize' !== $mode && ! current_user_can( 'manage_options' ) ) { wp_send_json_error( array( 'message' => 'این عملیات به دسترسی مدیر نیاز دارد.' ), 403 ); }
  if ( in_array( $mode, array( 'reclaim', 'optimize_reclaim' ), true ) && ( ! isset( $_POST['consent'] ) || 'delete-originals' !== $_POST['consent'] ) ) { wp_send_json_error( array( 'message' => 'حذف اصل تصویر به تأیید نیاز دارد.' ), 400 ); }
  $result = Pixora_Diagnostics::run( $id, $mode );
  if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
  $backup = get_post_meta( $id, self::BACKUP, true );
  wp_send_json_success( array( 'message' => 'عملیات انجام شد. برای مشاهدهٔ آخرین وضعیت، صفحه یا جزئیات تصویر را تازه کنید.', 'html' => self::media_button( get_post( $id ) ) ) );
 }
 public static function menu() { add_menu_page( 'Pixora | بهینه‌سازی تصاویر', 'Pixora', 'manage_options', 'pixora', array( __CLASS__, 'page' ), 'dashicons-format-image', 58 ); }
 public static function assets( $hook ) {
  if ( ! in_array( $hook, array( 'toplevel_page_pixora', 'upload.php', 'post.php', 'post-new.php' ), true ) ) { return; }
  wp_enqueue_style( 'pixora', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.4.2' );
  wp_enqueue_script( 'pixora', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.4.2', true );
  wp_localize_script( 'pixora', 'pixora', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'pixora_batch' ), 'finished' => 'پردازش تمام شد', 'failed' => 'درخواست ناموفق بود؛ دوباره تلاش کنید.', 'working' => 'در حال فشرده‌سازی…', 'optimized' => '✓ فشرده‌شده با Pixora', 'stopped' => 'پردازش متوقف شد', 'processed' => 'انجام‌شده', 'skipped' => 'بدون تغییر', 'saved' => 'مگابایت کاهش حجم', 'freed' => 'مگابایت فضای آزادشده', 'remaining' => 'فایل اصلی حذف‌نشده', 'modeOptimize' => 'فشرده‌سازی تصاویر', 'modeRestore' => 'بازگردانی تصاویر', 'modeReclaim' => 'حذف اصل تصاویر', 'modeCombined' => 'فشرده‌سازی و آزادسازی', 'failedCount' => 'ناموفق', 'partialCount' => 'WebP آماده، اصل محفوظ', 'copied' => 'گزارش کپی شد', 'copyFailed' => 'کپی خودکار ممکن نشد؛ متن گزارش را انتخاب و کپی کنید.', 'deleteConfirm' => 'اصل تصویر پس از تأیید آدرس‌ها حذف می‌شود و بازگردانی فقط با بکاپ مستقل ممکن است. ادامه می‌دهید؟', 'resume' => 'ادامهٔ پردازش از آخرین تصویر تأییدشده', 'restart' => 'شروع دوباره از ابتدا', 'consent' => 'ابتدا تأیید کنید که بکاپ مستقل دارید و اصل تصاویر قابل بازگردانی نخواهد بود.' ) );
 }
 public static function page() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $settings = self::options(); $before = 0; $after = 0; $count = 0; $disk = 0; $host = Pixora_Storage::host();
  global $wpdb;
  $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
  foreach ( $rows as $row ) { $data = maybe_unserialize( $row ); if ( is_array( $data ) ) { $before += $data['before']; $after += $data['after']; ++$count; $disk += $data['disk_freed'] ?? 0; } }
  ?>
  <div class="wrap pixora-app" dir="rtl" lang="fa">
   <header class="pixora-hero"><div><span class="pixora-eyebrow">تصاویر سبک‌تر، تجربهٔ سریع‌تر</span><h1>Pixora <span>پیکسورا</span></h1><p>بهینه‌سازی تصاویر و آزادسازی اختیاری فضای دیسک؛ همه‌چیز در کنترل شماست.</p></div><span class="pixora-version">نسخهٔ ۰.۴.۲ · ساختهٔ tahanoa</span></header>
   <?php settings_errors( self::OPTION ); ?>
   <?php if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) : ?><div class="notice notice-error inline"><p>برای تبدیل تصاویر، پشتیبانی WebP را در GD یا Imagick سرور فعال کنید.</p></div><?php endif; ?>
   <section class="pixora-stats" aria-label="آمار بهینه‌سازی">
    <div class="pixora-stat"><span>تصاویر بهینه‌شده</span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong><small>پیوست در کتابخانهٔ رسانه</small></div>
    <div class="pixora-stat"><span>کاهش حجم تصاویر</span><strong><?php echo esc_html( size_format( max( 0, $before - $after ) ) ); ?></strong><small>در مجموع اندازه‌های تولیدشده</small></div>
    <div class="pixora-stat"><span>درصد کاهش حجم</span><strong><?php echo esc_html( number_format_i18n( $before ? 100 * ( $before - $after ) / $before : 0, 1 ) ); ?>٪</strong><small>نسبت به نسخه‌های اصلی</small></div>
   </section>
   <section class="pixora-card pixora-storage"><h2>حذف اصل تصاویر و آزادسازی فضای دیسک</h2>
    <p>وب‌سرور شناسایی‌شده: <strong><?php echo esc_html( $host['name'] ); ?></strong> · فضای آزادشدهٔ ثبت‌شده: <strong><?php echo esc_html( size_format( $disk ) ); ?></strong></p>
    <p class="pixora-muted">با یک دکمه تصاویر جدید و قبلی را بهینه و اصل آن‌ها را آزاد کنید. برای هر تصویر، هدایت آدرس‌های قدیمی به WebP تنظیم و با درخواست واقعی آزمایش می‌شود؛ در صورت شکست بررسی، فایل اصلی حفظ می‌شود. تشخیص نوع وب‌سرور به‌تنهایی تأیید سازگاری نیست.</p>
    <?php if ( ! $host['supported'] ) : ?><p id="pixora-storage-reason" class="pixora-storage-warning">دکمهٔ حذف غیرفعال است: هاست سازگار با هدایت خودکار آدرس‌ها تشخیص داده نشد. برای حفظ تصاویر سایت، در Nginx یا هاست ناشناخته اصل تصاویر حذف نمی‌شود. پشتیبانی هاست باید امکان استفاده از قواعد ‎.htaccess را بررسی کند.</p><?php endif; ?>
    <label class="pixora-toggle"><input type="checkbox" id="pixora-cleanup-consent" <?php disabled( ! $host['supported'] ); ?>><span>بکاپ مستقل دارم و می‌پذیرم با حذف اصل تصاویر، بازگردانی از داخل افزونه ممکن نیست.</span></label>
    <p><button type="button" class="button button-primary" data-pixora="optimize_reclaim" <?php if ( ! $host['supported'] ) : ?>disabled data-pixora-unavailable="true" aria-describedby="pixora-storage-reason"<?php endif; ?>>فشرده‌سازی و حذف اصل در یک مرحله</button></p>
    <p class="pixora-muted">قواعد هدایت در پوشهٔ تصاویر باقی می‌مانند و به فعال بودن افزونه وابسته نیستند؛ هنگام مهاجرت یا بازیابی، فایل‌های مخفی ‎.htaccess را هم منتقل کنید. حذف یا تغییر این قواعد می‌تواند لینک‌های قدیمی را خراب کند. تصاویر تازه‌بهینه‌شده، نسخهٔ اولیهٔ بزرگ وردپرس را هم پوشش می‌دهند؛ در بهینه‌سازی‌های نسخه‌های قدیمی، فایل‌های فاقد نگاشت حفظ می‌شوند.</p>
   </section>
   <div class="pixora-layout">
    <section class="pixora-card"><h2>تنظیمات فشرده‌سازی</h2><p class="pixora-muted">تعادل دلخواه خود را میان کیفیت و حجم انتخاب کنید.</p>
     <form method="post" action="options.php"><?php settings_fields( 'pixora' ); ?>
      <label class="pixora-toggle"><input type="checkbox" name="pixora_settings[automatic]" value="1" <?php checked( $settings['automatic'], 1 ); ?>><span><strong>فشرده‌سازی خودکار</strong><small>نسخهٔ WebP جدا ساخته شود؛ اگر آزادسازی خودکار خاموش باشد، مصرف دیسک بیشتر می‌شود.</small></span></label>
      <label class="pixora-toggle"><input type="checkbox" name="pixora_settings[auto_cleanup]" value="1" <?php checked( $settings['auto_cleanup'], 1 ); disabled( ! $host['supported'] ); ?>><span><strong>آزادسازی خودکار پس از آپلود</strong><small>پس از تبدیل موفق، حذف اصل با زمان‌بندی وردپرس و پس از بررسی آدرس انجام شود؛ نیازمند فعال بودن فشرده‌سازی خودکار است.</small></span></label>
      <label class="pixora-toggle"><input type="checkbox" name="pixora_settings[cleanup_consent]" value="1" <?php checked( $settings['auto_cleanup'], 1 ); disabled( ! $host['supported'] ); ?>><span>برای تصاویر آینده بکاپ مستقل دارم و حذف غیرقابل‌بازگردانی اصل را می‌پذیرم.</span></label>
      <div class="pixora-quality"><label for="pixora-quality">کیفیت تصویر</label><input id="pixora-quality" type="number" name="pixora_settings[quality]" min="1" max="100" required value="<?php echo esc_attr( $settings['quality'] ); ?>"><div class="pixora-presets"><button type="button" class="button" data-pixora-quality="70">حجم کمتر · ۷۰</button><button type="button" class="button" data-pixora-quality="82">متعادل · ۸۲</button><button type="button" class="button" data-pixora-quality="92">کیفیت بالاتر · ۹۲</button></div><p class="pixora-muted">از ۱ تا ۱۰۰؛ مقدار پیشنهادی ۸۲ است. این تنظیم برای تبدیل‌های بعدی اعمال می‌شود.</p></div>
      <?php submit_button( 'ذخیرهٔ تنظیمات', 'primary', 'submit', true ); ?>
     </form>
    </section>
    <section class="pixora-card"><h2>مدیریت تصاویر قبلی</h2><p class="pixora-muted">تصاویر را به‌ترتیب و به‌صورت تدریجی پردازش کنید. تا پایان عملیات این صفحه را باز نگه دارید.</p>
     <div class="pixora-actions"><button type="button" class="button button-primary" data-pixora="optimize">فشرده‌سازی با حفظ اصل (مصرف دیسک بیشتر)</button><button type="button" class="button" data-pixora="restore">بازگردانی تصاویر اصلی</button><button type="button" class="button" id="pixora-stop" disabled>توقف پس از تصویر جاری</button></div>
     <p><button type="button" class="button" id="pixora-reset">شروع دوباره از ابتدا</button> <button type="button" class="button" id="pixora-copy-log">کپی گزارش برای پشتیبانی</button></p>
     <p id="pixora-progress" class="pixora-progress" role="status" aria-live="polite">آمادهٔ پردازش تصاویر</p><pre id="pixora-log" class="pixora-log" aria-label="گزارش پردازش"></pre>
    </section>
   </div>
   <aside class="pixora-note"><strong>حفظ لینک‌های تصاویر</strong><p>در حالت پیش‌فرض فایل اصلی حفظ می‌شود. در حالت آزادسازی، آدرس‌های قبلی از طریق قواعد هاست به WebP متصل می‌شوند و اصل فایل‌های بررسی‌شده حذف می‌شود. کاهش حجم به تصویر بستگی دارد و ۹۰٪ تضمین نمی‌شود. پیش از پردازش گروهی، از رسانه‌ها و پایگاه داده پشتیبان بگیرید.</p></aside>
   <footer class="pixora-footer">Pixora · طراحی و توسعه توسط <a href="https://github.com/Tahanoa" target="_blank" rel="noopener noreferrer">tahanoa</a></footer>
  </div>
  <?php
 }
}
Pixora::init();
if ( function_exists( 'register_deactivation_hook' ) ) { register_deactivation_hook( __FILE__, array( 'Pixora_Workflow', 'deactivate' ) ); }
