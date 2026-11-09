<?php
/** Media gallery and duplicate discovery. Discovery never deletes or rewrites media. */
if ( ! defined( 'ABSPATH' ) ) { exit; }
final class Pixora_Gallery {
 const META = '_pixora_fingerprint';
 const VERSION = 1;
 public static function init() {
  add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
  add_filter( 'update_attached_file', array( __CLASS__, 'metadata_changed' ), 20, 2 );
  add_filter( 'wp_update_attachment_metadata', array( __CLASS__, 'metadata_changed' ), 20, 2 );
  add_action( 'wp_ajax_pixora_scan', array( __CLASS__, 'scan' ) );
 }
 public static function metadata_changed( $metadata, $id ) {
  delete_post_meta( $id, self::META ); delete_post_meta( $id, '_pixora_sha' ); delete_post_meta( $id, '_pixora_pixels' ); delete_post_meta( $id, '_pixora_visual' );
  return $metadata;
 }
 public static function menu() { add_submenu_page( 'pixora', 'گالری و تصاویر تکراری', 'گالری تصاویر', 'manage_options', 'pixora-gallery', array( __CLASS__, 'page' ) ); }
 public static function fingerprint( $id ) {
  $file = get_attached_file( $id );
  if ( ! Pixora::safe_file( $file ) || ! is_readable( $file ) ) { return new WP_Error( 'missing', 'فایل محلی تصویر موجود نیست.' ); }
  $sha = hash_file( 'sha256', $file );
  if ( ! is_string( $sha ) || 64 !== strlen( $sha ) ) { return new WP_Error( 'read', 'خواندن فایل برای مقایسه ممکن نشد.' ); }
  $old = get_post_meta( $id, self::META, true );
  if ( is_array( $old ) && ( $old['version'] ?? 0 ) === self::VERSION && ( $old['sha'] ?? '' ) === $sha ) { update_post_meta( $id, '_pixora_sha', $old['sha'] ); update_post_meta( $id, '_pixora_pixels', $old['pixels'] ); update_post_meta( $id, '_pixora_visual', $old['visual'] ); return $old; }
  $data = array( 'version' => self::VERSION, 'id' => (int) $id, 'sha' => $sha, 'bytes' => filesize( $file ), 'pixels' => '', 'visual' => '', 'rgb' => array(), 'width' => 0, 'height' => 0, 'note' => '' );
  $mime = wp_get_image_mime( $file );
  $size = @getimagesize( $file );
  if ( $size ) { $data['width'] = $size[0]; $data['height'] = $size[1]; }
  $limit = ini_get( 'memory_limit' );
  $needed = $size ? (float) $size[0] * $size[1] * 10 + 16 * MB_IN_BYTES : PHP_INT_MAX;
  $can_decode = function_exists( 'imagecreatefromstring' ) && $size && in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) && filesize( $file ) <= 32 * MB_IN_BYTES && ( '-1' === $limit || $needed < wp_convert_hr_to_bytes( $limit ) - memory_get_usage( true ) );
  if ( 'image/png' === $mime && Pixora_Inplace::animated( $file ) ) { $can_decode = false; $data['note'] = 'PNG متحرک: فقط تطابق فایل بررسی شد.'; }
  if ( 'image/webp' === $mime ) { $header = file_get_contents( $file, false, null, 0, 21 ); if ( is_string( $header ) && strlen( $header ) >= 21 && 'VP8X' === substr( $header, 12, 4 ) && ( ord( $header[20] ) & 2 ) ) { $can_decode = false; $data['note'] = 'WebP متحرک: فقط تطابق فایل بررسی شد.'; } }
  if ( 'image/jpeg' === $mime && function_exists( 'exif_read_data' ) ) {
   $exif = @exif_read_data( $file );
   if ( ! empty( $exif['Orientation'] ) && 1 !== (int) $exif['Orientation'] ) { $can_decode = false; $data['note'] = 'جهت EXIF: فقط تطابق فایل بررسی شد.'; }
  }
  $image = $can_decode ? @imagecreatefromstring( file_get_contents( $file ) ) : false;
  if ( $image ) {
   try {
    $width = imagesx( $image ); $height = imagesy( $image );
    // Exact decoded RGBA comparison is independent of filename and container format.
    if ( (float) $width * $height <= 2000000 ) {
     $hash = hash_init( 'sha256' ); hash_update( $hash, $width . 'x' . $height . ':' );
     $truecolor = imageistruecolor( $image );
     for ( $y = 0; $y < $height; ++$y ) {
      $row = '';
      for ( $x = 0; $x < $width; ++$x ) {
       $value = imagecolorat( $image, $x, $y );
       if ( $truecolor ) { $r = ( $value >> 16 ) & 255; $g = ( $value >> 8 ) & 255; $b = $value & 255; $a = ( $value >> 24 ) & 127; }
       else { $c = imagecolorsforindex( $image, $value ); $r = $c['red']; $g = $c['green']; $b = $c['blue']; $a = $c['alpha']; }
       if ( 127 === $a ) { $r = 0; $g = 0; $b = 0; }
       $row .= pack( 'C4', $r, $g, $b, $a );
      }
      hash_update( $hash, $row );
     }
     $data['pixels'] = hash_final( $hash );
    } else { $data['note'] = 'بیش از ۲ میلیون پیکسل: تطابق فایل و شباهت ظاهری بررسی شد.'; }
    $small = imagecreatetruecolor( 9, 8 );
    imagefill( $small, 0, 0, imagecolorallocate( $small, 255, 255, 255 ) );
    imagealphablending( $small, true ); imagecopyresampled( $small, $image, 0, 0, 0, 0, 9, 8, $width, $height );
    $bits = ''; $rgb = array( 0, 0, 0 );
    for ( $y = 0; $y < 8; ++$y ) {
     $previous = null;
     for ( $x = 0; $x < 9; ++$x ) {
      $color = imagecolorat( $small, $x, $y ); $r = ( $color >> 16 ) & 255; $g = ( $color >> 8 ) & 255; $b = $color & 255;
      $rgb[0] += $r; $rgb[1] += $g; $rgb[2] += $b;
      $gray = 299 * $r + 587 * $g + 114 * $b;
      if ( null !== $previous ) { $bits .= $previous > $gray ? '1' : '0'; }
      $previous = $gray;
     }
    }
    imagedestroy( $small );
    $data['visual'] = implode( '', array_map( function( $part ) { return dechex( bindec( $part ) ); }, str_split( $bits, 4 ) ) );
    $data['rgb'] = array_map( function( $v ) { return (int) round( $v / 72 ); }, $rgb );
   } finally { imagedestroy( $image ); }
  } elseif ( ! $data['note'] ) { $data['note'] = 'فقط تطابق فایل بررسی شد؛ فرمت، GD یا حافظه برای مقایسهٔ پیکسلی مناسب نیست.'; }
  update_post_meta( $id, '_pixora_sha', $data['sha'] );
  update_post_meta( $id, '_pixora_pixels', $data['pixels'] );
  update_post_meta( $id, '_pixora_visual', $data['visual'] );
  update_post_meta( $id, self::META, $data );
  return $data;
 }
 public static function distance( $a, $b ) {
  if ( ! preg_match( '/\A[0-9a-f]{16}\z/', $a ) || ! preg_match( '/\A[0-9a-f]{16}\z/', $b ) ) { return 65; }
  $count = 0;
  for ( $i = 0; $i < 16; ++$i ) { $count += substr_count( decbin( hexdec( $a[$i] ) ^ hexdec( $b[$i] ) ), '1' ); }
  return $count;
 }
 public static function classify( $a, $b ) {
  if ( $a['sha'] === $b['sha'] ) { return 'file'; }
  if ( ! empty( $a['pixels'] ) && $a['pixels'] === $b['pixels'] ) { return 'pixels'; }
  if ( empty( $a['visual'] ) || empty( $b['visual'] ) || empty( $a['height'] ) || empty( $b['height'] ) || count( $a['rgb'] ) !== 3 || count( $b['rgb'] ) !== 3 ) { return ''; }
  $ratio_a = $a['width'] / $a['height']; $ratio_b = $b['width'] / $b['height'];
  if ( $ratio_a <= 0 || abs( $ratio_a - $ratio_b ) / $ratio_a > 0.02 ) { return ''; }
  foreach ( array( 0, 1, 2 ) as $channel ) { if ( abs( $a['rgb'][$channel] - $b['rgb'][$channel] ) > 20 ) { return ''; } }
  return self::distance( $a['visual'], $b['visual'] ) <= 4 ? 'similar' : '';
 }
 public static function scan() {
  check_ajax_referer( 'pixora_batch', 'nonce' );
  if ( ! current_user_can( 'manage_options' ) || ! current_user_can( 'upload_files' ) ) { wp_send_json_error( array( 'message' => 'اجازهٔ بررسی تصاویر را ندارید.' ), 403 ); }
  global $wpdb;
  $cursor = absint( $_POST['cursor'] ?? 0 );
  $id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type='attachment' AND post_status<>'trash' AND post_mime_type LIKE 'image/%%' AND ID > %d ORDER BY ID ASC LIMIT 1", $cursor ) );
  if ( ! $id ) { wp_send_json_success( array( 'done' => true ) ); }
  if ( ! current_user_can( 'edit_post', $id ) ) { $result = new WP_Error( 'access', 'دسترسی به این تصویر ندارید.' ); }
  else { $result = self::fingerprint( $id ); }
  wp_send_json_success( array( 'done' => false, 'cursor' => $id, 'message' => is_wp_error( $result ) ? $result->get_error_message() : ( $result['note'] ?: 'مقایسهٔ فایل، پیکسل و شباهت آماده شد.' ) ) );
 }
 public static function files( $id ) {
  $current = get_attached_file( $id ); $metadata = wp_get_attachment_metadata( $id ); $paths = array( $current );
  foreach ( $metadata['sizes'] ?? array() as $size ) { $paths[] = dirname( $current ) . '/' . $size['file']; }
  if ( ! empty( $metadata['original_image'] ) ) { $paths[] = dirname( $current ) . '/' . $metadata['original_image']; }
  $backup = get_post_meta( $id, Pixora::BACKUP, true );
  if ( is_array( $backup ) ) {
   $paths = array_merge( $paths, $backup['sources'] ?? array( $backup['file'] ), $backup['generated'] ?? array() );
   foreach ( $backup['metadata']['sizes'] ?? array() as $size ) { $paths[] = dirname( $backup['file'] ) . '/' . $size['file']; }
   if ( ! empty( $backup['metadata']['original_image'] ) ) { $paths[] = dirname( $backup['file'] ) . '/' . $backup['metadata']['original_image']; }
  }
  $copies = get_post_meta( $id, Pixora_Webp_Cleanup::COPIES, true );
  if ( is_array( $copies ) ) { $paths = array_merge( $paths, $copies ); }
  $count = 0; $bytes = 0;
  foreach ( array_unique( $paths ) as $path ) { if ( Pixora::safe_file( $path ) ) { ++$count; $bytes += filesize( $path ); } }
  return array( 'count' => $count, 'bytes' => $bytes );
 }
 public static function page() {
  if ( ! current_user_can( 'manage_options' ) ) { return; }
  $page = max( 1, absint( $_GET['pixora_page'] ?? 1 ) );
  $search = isset( $_GET['pixora_search'] ) ? sanitize_text_field( wp_unslash( $_GET['pixora_search'] ) ) : '';
  $query = new WP_Query( array( 'post_type' => 'attachment', 'post_status' => 'inherit', 'post_mime_type' => 'image', 'posts_per_page' => 24, 'paged' => $page, 's' => $search ) );
  ?>
  <div class="wrap pixora-app" dir="rtl" lang="fa"><header class="pixora-hero"><div><h1>گالری Pixora</h1><p>پیوست‌های کتابخانه، نسخه‌های قابل ردیابی و موارد تکراری را در یک‌جا بررسی کنید. اندازه‌های خودکار وردپرس و نسخه‌های یک پیوست، تکرار سهوی فرض نمی‌شوند.</p></div></header>
   <section class="pixora-card"><h2>یافتن تصاویر تکراری با پسوندهای متفاوت</h2><p>تطابق بایت‌های فایل، تطابق پیکسل‌های رمزگشایی‌شده و شباهت احتمالی جدا گزارش می‌شوند. تفاوت کیفیت JPEG یا تبدیل با اتلاف WebP معمولاً «مشابه احتمالی» است و اثبات تکراری‌بودن نیست. این بخش هیچ تصویری را حذف نمی‌کند.</p>
    <button type="button" class="button button-primary" id="pixora-scan">بررسی یا ادامهٔ بررسی تصاویر</button> <button type="button" class="button" id="pixora-scan-stop" disabled>توقف پس از تصویر جاری</button> <button type="button" class="button" id="pixora-scan-reset">بررسی دوباره از ابتدا</button>
    <p id="pixora-scan-status" role="status" aria-live="polite">پس از پایان بررسی، صفحه را تازه کنید تا نتیجه‌ها نمایش داده شوند.</p>
    <?php self::results(); ?>
   </section>
   <form method="get" class="pixora-gallery-search"><input type="hidden" name="page" value="pixora-gallery"><label for="pixora-gallery-search">جست‌وجوی عنوان تصویر</label> <input id="pixora-gallery-search" type="search" name="pixora_search" value="<?php echo esc_attr( $search ); ?>"> <button class="button">جست‌وجو</button></form>
   <div class="pixora-gallery-grid">
    <?php foreach ( $query->posts as $post ) : ?>
     <article class="pixora-card pixora-gallery-item"><?php echo wp_get_attachment_image( $post->ID, 'medium', false, array( 'loading' => 'lazy' ) ); ?><h3><?php echo esc_html( get_the_title( $post ) ?: 'بدون عنوان' ); ?></h3><p>#<?php echo esc_html( $post->ID ); ?> · <?php echo esc_html( get_post_mime_type( $post ) ); ?> · <?php $file = get_attached_file( $post->ID ); echo esc_html( Pixora::safe_file( $file ) ? size_format( filesize( $file ) ) : 'فایل محلی موجود نیست' ); ?></p><p><?php $stored = self::files( $post->ID ); echo esc_html( sprintf( 'فایل‌های قابل ردیابی این پیوست: %s · مجموع %s', number_format_i18n( $stored['count'] ), size_format( $stored['bytes'] ) ) ); ?></p><p><a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>">جزئیات رسانه در وردپرس</a></p><?php echo Pixora::media_button( $post ); ?></article>
    <?php endforeach; ?>
   </div>
   <?php if ( ! $query->posts ) : ?><p>تصویری یافت نشد.</p><?php endif; ?>
   <nav aria-label="صفحه‌های گالری"><?php for ( $number = max( 1, $page - 2 ); $number <= min( $query->max_num_pages, $page + 2 ); ++$number ) : ?><a class="button" <?php if ( $number === $page ) { echo 'aria-current="page"'; } ?> href="<?php echo esc_url( add_query_arg( array( 'page' => 'pixora-gallery', 'pixora_page' => $number, 'pixora_search' => $search ), admin_url( 'admin.php' ) ) ); ?>"><?php echo esc_html( number_format_i18n( $number ) ); ?></a> <?php endfor; ?></nav>
  </div>
  <?php
 }
 public static function results() {
  global $wpdb;
  $group_page = max( 0, absint( $_GET['pixora_groups'] ?? 0 ) );
  $groups = $wpdb->get_results( $wpdb->prepare( "SELECT m.meta_key,m.meta_value,COUNT(DISTINCT m.post_id) AS total FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='attachment' AND p.post_status<>'trash' AND m.meta_key IN ('_pixora_sha','_pixora_pixels') AND m.meta_value<>'' GROUP BY m.meta_key,m.meta_value HAVING COUNT(DISTINCT m.post_id)>1 ORDER BY m.meta_key,m.meta_value LIMIT 11 OFFSET %d", $group_page * 10 ) );
  echo '<h3>تکرار دقیق در تمام تصاویر نمایه‌شده</h3><p>این بخش میان تمام تصاویر بررسی‌شده جست‌وجو می‌کند، حتی وقتی دو تصویر در صفحه‌های متفاوت گالری باشند. یک گروه ممکن است هم در تطابق فایل و هم در تطابق پیکسل دیده شود.</p>';
  foreach ( array_slice( $groups, 0, 10 ) as $group ) {
   $ids = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT m.post_id FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE p.post_type='attachment' AND p.post_status<>'trash' AND m.meta_key=%s AND m.meta_value=%s ORDER BY m.post_id LIMIT 20", $group->meta_key, $group->meta_value ) );
   echo '<div class="pixora-duplicate"><strong>' . esc_html( '_pixora_sha' === $group->meta_key ? 'فایل کاملاً یکسان' : 'پیکسل یکسان با امکان تفاوت فرمت' ) . ' · ' . esc_html( $group->total ) . ' تصویر</strong><div>';
   foreach ( $ids as $id ) { if ( current_user_can( 'edit_post', $id ) ) { echo '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'loading' => 'lazy' ) ) . '<span>#' . esc_html( $id ) . '</span></a>'; } }
   echo '</div><small>حداکثر ۲۰ نمونه از هر گروه نمایش داده می‌شود.</small></div>';
  }
  if ( ! $groups ) { echo '<p>گروه تکرار دقیق یافت نشد؛ ابتدا بررسی تصاویر را اجرا کنید.</p>'; }
  if ( $group_page ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'pixora-gallery', 'pixora_groups' => $group_page - 1 ), admin_url( 'admin.php' ) ) ) . '">گروه‌های قبلی</a> '; }
  if ( count( $groups ) > 10 ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'pixora-gallery', 'pixora_groups' => $group_page + 1 ), admin_url( 'admin.php' ) ) ) . '">گروه‌های بعدی</a>'; }
  echo '<h3>شباهت احتمالی؛ بررسی کمکی</h3>';
  // Each source page searches candidates across the entire index, not only within its page.
  $offset = max( 0, absint( $_GET['pixora_compare'] ?? 0 ) );
  $rows = $wpdb->get_col( $wpdb->prepare( "SELECT m.meta_value FROM {$wpdb->postmeta} m INNER JOIN {$wpdb->posts} p ON p.ID=m.post_id WHERE m.meta_key=%s AND p.post_type='attachment' AND p.post_status<>'trash' ORDER BY m.post_id ASC LIMIT 21 OFFSET %d", self::META, $offset ) );
  $more = count( $rows ) > 20; $rows = array_slice( $rows, 0, 20 ); $found = 0;
  echo '<p>هر صفحه تا ۲۰ تصویر مبدأ را با نامزدهای مشابه در کل نمایه مقایسه می‌کند؛ برای هر مبدأ حداکثر ۱۰۰ نامزد بررسی می‌شود. نتیجهٔ مشابهت، مجوز حذف نیست.</p>';
  foreach ( $rows as $row ) {
   $source = maybe_unserialize( $row );
   if ( ! is_array( $source ) || empty( $source['visual'] ) || ! current_user_can( 'edit_post', $source['id'] ) ) { continue; }
   $clauses = array(); $parameters = array( $source['id'] );
   for ( $band = 0; $band < 8; ++$band ) { $clauses[] = '(SUBSTRING(v.meta_value,' . ( $band * 2 + 1 ) . ',2)=%s)'; $parameters[] = substr( $source['visual'], $band * 2, 2 ); }
   // <=4 differing bits implies at least four identical 8-bit bands.
   $sql = "SELECT DISTINCT v.post_id FROM {$wpdb->postmeta} v INNER JOIN {$wpdb->posts} p ON p.ID=v.post_id WHERE v.meta_key='_pixora_visual' AND v.post_id>%d AND LENGTH(v.meta_value)=16 AND p.post_type='attachment' AND p.post_status<>'trash' AND (" . implode( '+', $clauses ) . ')>=4 ORDER BY v.post_id LIMIT 101';
   $ids = $wpdb->get_col( $wpdb->prepare( $sql, $parameters ) );
   $limited = count( $ids ) > 100; $ids = array_slice( $ids, 0, 100 );
   update_meta_cache( 'post', $ids ); $matches = array();
   foreach ( $ids as $id ) { $candidate = get_post_meta( $id, self::META, true ); if ( is_array( $candidate ) && current_user_can( 'edit_post', $id ) && 'similar' === self::classify( $source, $candidate ) ) { $matches[] = (int) $id; } }
   if ( ! $matches ) { continue; }
   ++$found; echo '<div class="pixora-duplicate"><strong>مشابه احتمالی؛ نیازمند بررسی شما</strong><div>';
   foreach ( array_merge( array( $source['id'] ), $matches ) as $id ) { echo '<a href="' . esc_url( get_edit_post_link( $id ) ) . '">' . wp_get_attachment_image( $id, 'thumbnail', false, array( 'loading' => 'lazy' ) ) . '<span>#' . esc_html( $id ) . '</span></a>'; }
   echo '</div>';
   if ( $limited ) { echo '<small>نامزدهای بیشتری وجود دارند؛ فقط ۱۰۰ نامزد نخست بررسی شدند.</small>'; }
   echo '</div>';
  }
  if ( ! $found ) { echo '<p>برای مبدأهای این صفحه، مشابه احتمالی یافت نشد؛ صفحه‌های دیگر را هم بررسی کنید.</p>'; }
  if ( $offset ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'pixora-gallery', 'pixora_compare' => max( 0, $offset - 20 ) ), admin_url( 'admin.php' ) ) ) . '">مبدأهای قبلی</a> '; }
  if ( $more ) { echo '<a class="button" href="' . esc_url( add_query_arg( array( 'page' => 'pixora-gallery', 'pixora_compare' => $offset + 20 ), admin_url( 'admin.php' ) ) ) . '">مبدأهای بعدی</a>'; }
 }
}
