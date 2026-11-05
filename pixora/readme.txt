=== Pixora ===
Contributors: tahanoa
Tags: webp, images, optimization, media
Requires at least: 6.5
Requires PHP: 7.4
Stable tag: 0.3.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Reversible WebP conversion for JPEG and PNG attachments with original URL preservation.

== Description ==
Convert new uploads automatically or process your existing media library one attachment at a time. Configure quality, view image payload savings, and restore originals. Requires GD or Imagick with WebP encoding support.

Original files stay on disk. Existing hardcoded URLs are preserved and continue serving originals; newly generated attachment output uses WebP. Back up uploads and your database before processing. Savings vary. Disk usage increases because originals are retained. Animated PNGs, GIFs, and SVGs are skipped. External media storage is not supported in this version.

== Installation ==
1. Upload the pixora folder to wp-content/plugins or install its ZIP in WordPress.
2. Activate Pixora.
3. Open Pixora in the main admin menu to configure quality and process or restore your library.

== Changelog ==
= 0.3.1 =
Always display the original-image deletion control, with a clear disabled reason on unsupported hosts.
= 0.3.0 =
Automatic Apache/LiteSpeed detection and explicit disk reclamation after verified legacy URL mapping. Nginx and unknown hosts retain originals. Requires an independent backup; restoration is unavailable after deletion. Rules persist in uploads .htaccess files and must be preserved during migration.
= 0.2.0 =
Persian RTL dashboard, standalone menu, tahanoa author credit, and per-image media library optimization.
= 0.1.0 =
Initial development release. Validate on staging before production deployment.
