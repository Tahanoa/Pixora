<?php
/**
 * Plugin Name: Pixora
 * Description: فشرده‌سازی تصاویر و تبدیل به WebP با حفظ فایل اصلی و امکان بازگردانی.
 * Version: 0.3.0
 * Author: tahanoa
 * Author URI: https://github.com/Tahanoa
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: pixora
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

require_once __DIR__ . '/class-pixora-storage.php';

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
  add_action( 'delete_attachment', array( __CLASS__, 'cleanup' ) );
 }
 public static function options() {
  return wp_parse_args( get_option( self::OPTION, array() ), array( 'quality' => 82, 'automatic' => 1 ) );
 }
 public static function sanitize( $input ) {
  return array( 'quality' => max( 1, min( 100, absint( $input['quality'] ?? 82 ) ) ), 'automatic' => empty( $input['automatic'] ) ? 0 : 1 );
 }
 public static function settings() {
  register_setting( 'pixora', self::OPTION, array( 'type' => 'array', 'sanitize_callback' => array( __CLASS__, 'sanitize' ) ) );
 }
 public static function on_upload( $metadata, $id ) {
  if ( self::options()['automatic'] && ! get_post_meta( $id, self::BACKUP, true ) ) {
   $result = self::optimize( $id, $metadata );
   if ( ! is_wp_error( $result ) ) { return $result; }
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
  // Atomic option insertion prevents simultaneous upload/batch requests for this attachment.
  $lock = 'pixora_lock_' . $id;
  if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', __( 'تصویر در حال پردازش است؛ کمی بعد دوباره تلاش کنید.', 'pixora' ) ); }
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
   $sources = array( $file );
   foreach ( $metadata['sizes'] ?? array() as $size ) { $sources[] = dirname( $file ) . '/' . $size['file']; }
   $sources = array_unique( $sources );
   $map = array(); $before = 0; $after = 0;
   foreach ( $sources as $source ) {
    if ( ! self::safe_file( $source ) ) { return new WP_Error( 'missing', __( 'یکی از اندازه‌های تصویر موجود نیست یا خارج از پوشهٔ بارگذاری است.', 'pixora' ) ); }
    $editor = wp_get_image_editor( $source );
    if ( is_wp_error( $editor ) ) { return $editor; }
    $quality = $editor->set_quality( self::options()['quality'] );
    if ( is_wp_error( $quality ) ) { return $quality; }
    $target = dirname( $source ) . '/' . wp_unique_filename( dirname( $source ), pathinfo( $source, PATHINFO_FILENAME ) . '-pixora.webp' );
    $saved = $editor->save( $target, 'image/webp' );
    if ( is_wp_error( $saved ) ) { return $saved; }
    $created[] = $saved['path'];
    if ( ! self::safe_file( $saved['path'] ) || 'image/webp' !== $saved['mime-type'] ) { return new WP_Error( 'encode', __( 'تبدیل تصویر به WebP ناموفق بود.', 'pixora' ) ); }
    $before += filesize( $source ); $after += filesize( $saved['path'] );
    $map[ $source ] = $saved;
   }
   if ( $after >= $before ) { return new WP_Error( 'no_saving', __( 'تصاویر اصلی کم‌حجم‌تر هستند و بدون تغییر حفظ شدند.', 'pixora' ) ); }
   $backup = array( 'file' => $file, 'metadata' => $metadata, 'mime' => get_post_mime_type( $id ), 'generated' => $created, 'before' => $before, 'after' => $after );
   if ( ! add_post_meta( $id, self::BACKUP, $backup, true ) ) { return new WP_Error( 'backup', __( 'ذخیرهٔ اطلاعات بازگردانی ممکن نشد.', 'pixora' ) ); }
   $new = $metadata;
   $new['file'] = _wp_relative_upload_path( $map[ $file ]['path'] );
   $new['filesize'] = filesize( $map[ $file ]['path'] );
   foreach ( $new['sizes'] ?? array() as $key => $size ) {
    $entry = $map[ dirname( $file ) . '/' . $size['file'] ];
    $new['sizes'][ $key ]['file'] = basename( $entry['path'] );
    $new['sizes'][ $key ]['mime-type'] = 'image/webp';
    $new['sizes'][ $key ]['filesize'] = filesize( $entry['path'] );
   }
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
   delete_option( $lock );
  }
 }
 public static function restore( $id ) {
  $lock = 'pixora_lock_' . $id;
  if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', __( 'تصویر در حال پردازش است؛ کمی بعد دوباره تلاش کنید.', 'pixora' ) ); }
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
  } finally { delete_option( $lock ); }
 }
 public static function cleanup( $id ) {
  $backup = get_post_meta( $id, self::BACKUP, true );
  if ( ! $backup ) { return; }
  $paths = array_merge( array( $backup['file'] ), $backup['generated'] );
  foreach ( $backup['metadata']['sizes'] ?? array() as $size ) { $paths[] = dirname( $backup['file'] ) . '/' . $size['file']; }
  foreach ( array_unique( $paths ) as $path ) { if ( self::safe_file( $path ) ) { wp_delete_file( $path ); } }
 }
 public static function batch() {
  check_ajax_referer( 'pixora_batch', 'nonce' );
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => __( 'اجازهٔ انجام این کار را ندارید.', 'pixora' ) ), 403 ); }
  global $wpdb;
  $mode = isset( $_POST['mode'] ) && is_string( $_POST['mode'] ) ? $_POST['mode'] : 'optimize';
  if ( ! in_array( $mode, array( 'optimize', 'restore', 'reclaim' ), true ) ) { wp_send_json_error( array( 'message' => 'عملیات نامعتبر است.' ), 400 ); }
  if ( 'reclaim' === $mode && ( ! isset( $_POST['consent'] ) || 'delete-originals' !== $_POST['consent'] ) ) { wp_send_json_error( array( 'message' => 'برای حذف اصل تصاویر، تأیید آگاهانه لازم است.' ), 400 ); }
  $restore = 'restore' === $mode;
  $cursor = absint( $_POST['cursor'] ?? 0 );
  $id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d AND post_mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY ID ASC LIMIT 1", $cursor ) );
  if ( ! $id ) { wp_send_json_success( array( 'done' => true ) ); }
  $id = (int) $id;
  if ( ! current_user_can( 'edit_post', $id ) ) { $result = new WP_Error( 'access', __( 'اجازهٔ ویرایش این رسانه را ندارید.', 'pixora' ) ); }
  elseif ( 'reclaim' === $mode ) { $result = Pixora_Storage::reclaim( $id ); }
  elseif ( $restore ) { $result = self::restore( $id ); }
  else { $result = self::optimize( $id ); }
  $backup = get_post_meta( $id, self::BACKUP, true );
  wp_send_json_success( array( 'done' => false, 'cursor' => $id, 'status' => is_wp_error( $result ) ? 'skipped' : 'processed', 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'عملیات با موفقیت انجام شد.', 'pixora' ), 'saved' => ! is_wp_error( $result ) && 'optimize' === $mode && $backup ? max( 0, $backup['before'] - $backup['after'] ) : 0, 'freed' => ! is_wp_error( $result ) && 'reclaim' === $mode ? $result['freed'] : 0, 'remaining' => ! is_wp_error( $result ) && 'reclaim' === $mode ? $result['remaining'] : 0 ) );
 }
 public static function media_button( $post ) {
  if ( ! current_user_can( 'upload_files' ) || ! current_user_can( 'edit_post', $post->ID ) || ! in_array( $post->post_mime_type, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) { return ''; }
  if ( get_post_meta( $post->ID, self::BACKUP, true ) ) { return '<span class="pixora-media-done">✓ فشرده‌شده با Pixora</span>'; }
  if ( 'image/webp' === $post->post_mime_type ) { return ''; }
  return '<button type="button" class="button button-small pixora-media-button" data-pixora-id="' . esc_attr( $post->ID ) . '">فشرده‌سازی با Pixora</button><span class="pixora-media-result" role="status" aria-live="polite"></span>';
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
  $result = self::optimize( $id );
  if ( is_wp_error( $result ) ) { wp_send_json_error( array( 'message' => $result->get_error_message() ) ); }
  $backup = get_post_meta( $id, self::BACKUP, true );
  wp_send_json_success( array( 'message' => sprintf( 'فشرده‌سازی انجام شد؛ %s کاهش حجم.', size_format( max( 0, $backup['before'] - $backup['after'] ) ) ) ) );
 }
 public static function menu() { add_menu_page( 'Pixora | بهینه‌سازی تصاویر', 'Pixora', 'manage_options', 'pixora', array( __CLASS__, 'page' ), 'dashicons-format-image', 58 ); }
 public static function assets( $hook ) {
  if ( ! in_array( $hook, array( 'toplevel_page_pixora', 'upload.php', 'post.php', 'post-new.php' ), true ) ) { return; }
  wp_enqueue_style( 'pixora', plugins_url( 'assets/admin.css', __FILE__ ), array(), '0.3.0' );
  wp_enqueue_script( 'pixora', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.3.0', true );
  wp_localize_script( 'pixora', 'pixora', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'pixora_batch' ), 'finished' => 'پردازش تمام شد', 'failed' => 'درخواست ناموفق بود؛ دوباره تلاش کنید.', 'working' => 'در حال فشرده‌سازی…', 'optimized' => '✓ فشرده‌شده با Pixora', 'stopped' => 'پردازش متوقف شد', 'processed' => 'انجام‌شده', 'skipped' => 'بدون تغییر', 'saved' => 'مگابایت کاهش حجم', 'freed' => 'مگابایت فضای آزادشده', 'remaining' => 'فایل اصلی حذف‌نشده', 'consent' => 'ابتدا تأیید کنید که بکاپ مستقل دارید و اصل تصاویر قابل بازگردانی نخواهد بود.' ) );
 }
 public static function page() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $settings = self::options(); $before = 0; $after = 0; $count = 0; $disk = 0; $host = Pixora_Storage::host();
  global $wpdb;
  $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
  foreach ( $rows as $row ) { $data = maybe_unserialize( $row ); if ( is_array( $data ) ) { $before += $data['before']; $after += $data['after']; ++$count; $disk += $data['disk_freed'] ?? 0; } }
  ?>
  <div class="wrap pixora-app" dir="rtl" lang="fa">
   <header class="pixora-hero"><div><span class="pixora-eyebrow">تصاویر سبک‌تر، تجربهٔ سریع‌تر</span><h1>Pixora <span>پیکسورا</span></h1><p>بهینه‌سازی تصاویر و آزادسازی اختیاری فضای دیسک؛ همه‌چیز در کنترل شماست.</p></div><span class="pixora-version">نسخهٔ ۰.۳.۰ · ساختهٔ tahanoa</span></header>
   <?php settings_errors( self::OPTION ); ?>
   <?php if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) : ?><div class="notice notice-error inline"><p>برای تبدیل تصاویر، پشتیبانی WebP را در GD یا Imagick سرور فعال کنید.</p></div><?php endif; ?>
   <section class="pixora-stats" aria-label="آمار بهینه‌سازی">
    <div class="pixora-stat"><span>تصاویر بهینه‌شده</span><strong><?php echo esc_html( number_format_i18n( $count ) ); ?></strong><small>پیوست در کتابخانهٔ رسانه</small></div>
    <div class="pixora-stat"><span>کاهش حجم تصاویر</span><strong><?php echo esc_html( size_format( max( 0, $before - $after ) ) ); ?></strong><small>در مجموع اندازه‌های تولیدشده</small></div>
    <div class="pixora-stat"><span>درصد کاهش حجم</span><strong><?php echo esc_html( number_format_i18n( $before ? 100 * ( $before - $after ) / $before : 0, 1 ) ); ?>٪</strong><small>نسبت به نسخه‌های اصلی</small></div>
   </section>
   <section class="pixora-card pixora-storage"><h2>آزادسازی فضای دیسک</h2>
    <p>وب‌سرور شناسایی‌شده: <strong><?php echo esc_html( $host['name'] ); ?></strong> · فضای آزادشدهٔ ثبت‌شده: <strong><?php echo esc_html( size_format( $disk ) ); ?></strong></p>
    <p class="pixora-muted">ابتدا تصاویر را فشرده کنید. برای هر تصویر، هدایت آدرس‌های قدیمی به WebP تنظیم و با درخواست واقعی آزمایش می‌شود؛ در صورت شکست بررسی، فایل اصلی حفظ می‌شود. تشخیص نوع وب‌سرور به‌تنهایی تأیید سازگاری نیست.</p>
    <?php if ( ! $host['supported'] ) : ?><p class="pixora-storage-warning">تنظیم خودکار روی این هاست فعال نیست. در Nginx یا هاست ناشناخته، تنظیم وب‌سرور باید توسط پشتیبانی هاست بررسی شود؛ اصل تصاویر حذف نمی‌شوند.</p><?php else : ?>
    <label class="pixora-toggle"><input type="checkbox" id="pixora-cleanup-consent"><span>بکاپ مستقل دارم و می‌پذیرم با حذف اصل تصاویر، بازگردانی از داخل افزونه ممکن نیست.</span></label>
    <p><button type="button" class="button" data-pixora="reclaim">بررسی آدرس‌ها و آزادسازی فضا</button></p>
    <?php endif; ?>
    <p class="pixora-muted">قواعد هدایت در پوشهٔ تصاویر باقی می‌مانند و به فعال بودن افزونه وابسته نیستند؛ هنگام مهاجرت یا بازیابی، فایل‌های مخفی ‎.htaccess را هم منتقل کنید. حذف یا تغییر این قواعد می‌تواند لینک‌های قدیمی را خراب کند. فایل اصلیِ بزرگ قبل از تغییر اندازهٔ وردپرس و فایل‌های فاقد نگاشت حفظ می‌شوند.</p>
   </section>
   <div class="pixora-layout">
    <section class="pixora-card"><h2>تنظیمات فشرده‌سازی</h2><p class="pixora-muted">تعادل دلخواه خود را میان کیفیت و حجم انتخاب کنید.</p>
     <form method="post" action="options.php"><?php settings_fields( 'pixora' ); ?>
      <label class="pixora-toggle"><input type="checkbox" name="pixora_settings[automatic]" value="1" <?php checked( $settings['automatic'], 1 ); ?>><span><strong>فشرده‌سازی خودکار</strong><small>تصاویر جدید هنگام بارگذاری به WebP تبدیل شوند.</small></span></label>
      <div class="pixora-quality"><label for="pixora-quality">کیفیت تصویر</label><input id="pixora-quality" type="number" name="pixora_settings[quality]" min="1" max="100" required value="<?php echo esc_attr( $settings['quality'] ); ?>"><p class="pixora-muted">از ۱ تا ۱۰۰؛ مقدار پیشنهادی ۸۲ است. این تنظیم برای تبدیل‌های بعدی اعمال می‌شود.</p></div>
      <?php submit_button( 'ذخیرهٔ تنظیمات', 'primary', 'submit', true ); ?>
     </form>
    </section>
    <section class="pixora-card"><h2>مدیریت تصاویر قبلی</h2><p class="pixora-muted">تصاویر را به‌ترتیب و به‌صورت تدریجی پردازش کنید. تا پایان عملیات این صفحه را باز نگه دارید.</p>
     <div class="pixora-actions"><button type="button" class="button button-primary" data-pixora="optimize">فشرده‌سازی تصاویر با Pixora</button><button type="button" class="button" data-pixora="restore">بازگردانی تصاویر اصلی</button><button type="button" class="button" id="pixora-stop" disabled>توقف پس از تصویر جاری</button></div>
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
