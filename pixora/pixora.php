<?php
/**
 * Plugin Name: Pixora
 * Description: Reversible WebP optimization for the WordPress media library.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 7.4
 * Text Domain: pixora
 * License: GPL-2.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) { exit; }

final class Pixora {
 const BACKUP = '_pixora_original';
 const OPTION = 'pixora_settings';

 public static function init() {
  add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'on_upload' ), 20, 2 );
  add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
  add_action( 'admin_init', array( __CLASS__, 'settings' ) );
  add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
  add_action( 'wp_ajax_pixora_batch', array( __CLASS__, 'batch' ) );
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
  if ( get_post_meta( $id, self::BACKUP, true ) ) { return new WP_Error( 'done', __( 'Already optimized.', 'pixora' ) ); }
  if ( ! in_array( get_post_mime_type( $id ), array( 'image/jpeg', 'image/png' ), true ) ) {
   return new WP_Error( 'format', __( 'Only JPEG and PNG images are supported.', 'pixora' ) );
  }
  if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) {
   return new WP_Error( 'support', __( 'Your server does not support WebP encoding.', 'pixora' ) );
  }
  // Atomic option insertion prevents simultaneous upload/batch requests for this attachment.
  $lock = 'pixora_lock_' . $id;
  if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', __( 'Image is being processed. Retry later.', 'pixora' ) ); }
  $created = array();
  try {
   $metadata = is_array( $metadata ) ? $metadata : wp_get_attachment_metadata( $id );
   $file = get_attached_file( $id );
   if ( ! self::safe_file( $file ) || empty( $metadata['file'] ) ) { return new WP_Error( 'missing', __( 'Original image or metadata is missing.', 'pixora' ) ); }
   // Animated PNGs must not be flattened by a server image editor.
   if ( 'image/png' === get_post_mime_type( $id ) ) {
    $handle = fopen( $file, 'rb' );
    if ( ! $handle ) { return new WP_Error( 'read', __( 'Cannot read image.', 'pixora' ) ); }
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
    if ( $animated ) { return new WP_Error( 'animated', __( 'Animated PNGs are preserved unchanged.', 'pixora' ) ); }
   }
   $sources = array( $file );
   foreach ( $metadata['sizes'] ?? array() as $size ) { $sources[] = dirname( $file ) . '/' . $size['file']; }
   $sources = array_unique( $sources );
   $map = array(); $before = 0; $after = 0;
   foreach ( $sources as $source ) {
    if ( ! self::safe_file( $source ) ) { return new WP_Error( 'missing', __( 'An image size is missing or outside uploads.', 'pixora' ) ); }
    $editor = wp_get_image_editor( $source );
    if ( is_wp_error( $editor ) ) { return $editor; }
    $quality = $editor->set_quality( self::options()['quality'] );
    if ( is_wp_error( $quality ) ) { return $quality; }
    $target = dirname( $source ) . '/' . wp_unique_filename( dirname( $source ), pathinfo( $source, PATHINFO_FILENAME ) . '-pixora.webp' );
    $saved = $editor->save( $target, 'image/webp' );
    if ( is_wp_error( $saved ) ) { return $saved; }
    $created[] = $saved['path'];
    if ( ! self::safe_file( $saved['path'] ) || 'image/webp' !== $saved['mime-type'] ) { return new WP_Error( 'encode', __( 'WebP conversion failed.', 'pixora' ) ); }
    $before += filesize( $source ); $after += filesize( $saved['path'] );
    $map[ $source ] = $saved;
   }
   if ( $after >= $before ) { return new WP_Error( 'no_saving', __( 'Original images are already smaller; kept unchanged.', 'pixora' ) ); }
   $backup = array( 'file' => $file, 'metadata' => $metadata, 'mime' => get_post_mime_type( $id ), 'generated' => $created, 'before' => $before, 'after' => $after );
   if ( ! add_post_meta( $id, self::BACKUP, $backup, true ) ) { return new WP_Error( 'backup', __( 'Could not save restoration data.', 'pixora' ) ); }
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
    return new WP_Error( 'persist', __( 'Could not update attachment data. Check database access before retrying.', 'pixora' ) );
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
  if ( ! add_option( $lock, time(), '', false ) ) { return new WP_Error( 'busy', __( 'Image is being processed. Retry later.', 'pixora' ) ); }
  try {
   $backup = get_post_meta( $id, self::BACKUP, true );
   if ( ! $backup || ! self::safe_file( $backup['file'] ) ) { return new WP_Error( 'missing', __( 'Original image is unavailable.', 'pixora' ) ); }
   update_attached_file( $id, $backup['file'] );
   wp_update_post( array( 'ID' => $id, 'post_mime_type' => $backup['mime'] ) );
   wp_update_attachment_metadata( $id, $backup['metadata'] );
   if ( get_attached_file( $id ) !== $backup['file'] || get_post_mime_type( $id ) !== $backup['mime'] || wp_get_attachment_metadata( $id ) !== $backup['metadata'] ) {
    return new WP_Error( 'persist', __( 'Restoration could not be saved. Recovery data was retained.', 'pixora' ) );
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
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => __( 'Permission denied.', 'pixora' ) ), 403 ); }
  global $wpdb;
  $restore = isset( $_POST['mode'] ) && 'restore' === $_POST['mode'];
  $cursor = absint( $_POST['cursor'] ?? 0 );
  $id = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND ID > %d AND post_mime_type IN ('image/jpeg','image/png','image/webp') ORDER BY ID ASC LIMIT 1", $cursor ) );
  if ( ! $id ) { wp_send_json_success( array( 'done' => true ) ); }
  $id = (int) $id;
  if ( ! current_user_can( 'edit_post', $id ) ) { $result = new WP_Error( 'access', __( 'Cannot edit this attachment.', 'pixora' ) ); }
  elseif ( $restore ) { $result = self::restore( $id ); }
  else { $result = self::optimize( $id ); }
  $backup = get_post_meta( $id, self::BACKUP, true );
  wp_send_json_success( array( 'done' => false, 'cursor' => $id, 'status' => is_wp_error( $result ) ? 'skipped' : 'processed', 'message' => is_wp_error( $result ) ? $result->get_error_message() : __( 'Completed.', 'pixora' ), 'saved' => ! is_wp_error( $result ) && ! $restore && $backup ? max( 0, $backup['before'] - $backup['after'] ) : 0 ) );
 }
 public static function menu() { add_media_page( 'Pixora', 'Pixora', 'manage_options', 'pixora', array( __CLASS__, 'page' ) ); }
 public static function assets( $hook ) {
  if ( 'media_page_pixora' !== $hook ) { return; }
  wp_enqueue_script( 'pixora', plugins_url( 'assets/admin.js', __FILE__ ), array(), '0.1.0', true );
  wp_localize_script( 'pixora', 'pixora', array( 'url' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'pixora_batch' ), 'finished' => __( 'Finished', 'pixora' ), 'failed' => __( 'Request failed. Retry to continue.', 'pixora' ) ) );
 }
 public static function page() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $settings = self::options(); $before = 0; $after = 0; $count = 0;
  global $wpdb;
  $rows = $wpdb->get_col( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s", self::BACKUP ) );
  foreach ( $rows as $row ) { $data = maybe_unserialize( $row ); if ( is_array( $data ) ) { $before += $data['before']; $after += $data['after']; ++$count; } }
  ?>
  <div class="wrap"><h1>Pixora</h1>
  <p><?php esc_html_e( 'Create lighter WebP images while retaining originals. Existing image URLs remain available. Savings depend on the source image; 90% is not guaranteed.', 'pixora' ); ?></p>
  <p><?php echo esc_html( sprintf( __( 'Optimized: %1$d attachments. Image payload saved: %2$s (%3$s%%). Originals are retained, so disk usage increases.', 'pixora' ), $count, size_format( max( 0, $before - $after ) ), $before ? round( 100 * ( $before - $after ) / $before, 1 ) : 0 ) ); ?></p>
  <?php if ( ! wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) ) ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'Enable WebP support in GD or Imagick on your server.', 'pixora' ); ?></p></div><?php endif; ?>
  <form method="post" action="options.php"><?php settings_fields( 'pixora' ); ?>
  <p><label><input type="checkbox" name="pixora_settings[automatic]" value="1" <?php checked( $settings['automatic'], 1 ); ?>> <?php esc_html_e( 'Automatically optimize new uploads', 'pixora' ); ?></label></p>
  <p><label><?php esc_html_e( 'WebP quality (1–100)', 'pixora' ); ?> <input type="number" name="pixora_settings[quality]" min="1" max="100" value="<?php echo esc_attr( $settings['quality'] ); ?>"></label></p>
  <?php submit_button(); ?></form>
  <p><?php esc_html_e( 'Existing content with hardcoded image URLs keeps its original images. Attachment-based output uses WebP. Animated PNG, GIF and SVG are left unchanged. Back up your media and database before bulk processing.', 'pixora' ); ?></p>
  <button class="button button-primary" data-pixora="optimize"><?php esc_html_e( 'Optimize existing images', 'pixora' ); ?></button>
  <button class="button" data-pixora="restore"><?php esc_html_e( 'Restore original images', 'pixora' ); ?></button>
  <button class="button" id="pixora-stop" disabled><?php esc_html_e( 'Stop after current image', 'pixora' ); ?></button>
  <p id="pixora-progress" role="status" aria-live="polite"></p><pre id="pixora-log" style="max-height:300px;overflow:auto;white-space:pre-wrap"></pre></div>
  <?php
 }
}
Pixora::init();
