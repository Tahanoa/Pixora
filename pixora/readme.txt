=== Pixora ===
Contributors: tahanoa
Tags: webp, images, optimization, media
Requires at least: 6.5
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 0.9.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Persian image optimization and optional verified disk reclamation, with one-step and background workflows.

== Description ==
Browser conversion is enabled by default with WebP-only upload mode. Native WordPress admin multi-file uploaders convert JPEG/PNG locally before sending the multipart request. Encoding uses the browser's native WebP codec, sequential workers where available, and local-page fallback. No Squoosh service, remote encoder, CDN script or WASM package is used. Dimensions and transparency are retained; EXIF/GPS metadata is stripped by Canvas. Lossy output and color handling depend on the browser; smaller files are not guaranteed. Inputs are limited to 64 MiB, 24 million pixels and 16383 pixels per side. Animated PNG and failed conversions are rejected without silently sending the source. REST/direct imports, some block uploads and simple HTML forms use the existing server-side WebP-only path. WordPress still creates thumbnails on the host.

For upload paths outside the browser integration, JPEG/PNG files received through the standard WordPress upload/sideload path are converted before attachment registration. Only the validated WebP is retained; original JPEG/PNG is removed before success is returned. URLs and generated sizes start as WebP and need no redirects. Failed conversion rejects the upload; animated PNG is rejected in this mode, while existing WebP and other formats pass through. WordPress thumbnails and large-image scaled copies can still create multiple WebP files. Keep your own original; in-plugin restoration is unavailable. Disable WebP-only upload mode to use legacy upload settings. Existing attachments still require delivery verification before original deletion.

Pixora converts JPEG/PNG to WebP. Same-format compression and automatic fallback have been removed. Original deletion requires verified WebP delivery; failed verification preserves originals.

Convert JPEG and PNG uploads to WebP, preserve transparency, choose quality presets, process existing images, and optionally reclaim originals after verifying all legacy URLs. A standalone Persian RTL dashboard, per-image media controls, separate payload/disk savings, resumable batches, and support-report copying are included.

Original deletion is opt-in and irreversible without an independent backup. Automatic upload cleanup uses WordPress Cron after conversion; its timing depends on the site's scheduler. Apache/LiteSpeed routing support and successful exact-response checks are mandatory. Nginx or unknown hosts retain originals. Static rules remain in uploads .htaccess files after deactivation and must be preserved during migration.

Existing hardcoded URLs retain their original files until reclamation, then serve verified WebP through routing rules. The full WordPress original_image is included in new conversions. Old conversions may retain unmapped files. Animated PNG, GIF, SVG, pre-existing WebP and external media storage are not converted. Savings vary; no fixed percentage or lossless quality is promised.

== Installation ==
1. Upload the pixora folder to wp-content/plugins or install its ZIP.
2. Activate Pixora and open its top-level admin menu.
3. Back up uploads and the database before enabling deletion.
4. Choose compression only, one-step optimize and reclaim, or opt-in scheduled cleanup for future uploads.

== Frequently Asked Questions ==
= Does compression alone delete originals? =
No. Use the explicit one-step cleanup action or enable automatic cleanup with backup consent.

= Can I restore deleted originals? =
Only from your independent backup. In-plugin restoration is available before original deletion.

= Does cleanup run immediately after upload? =
Conversion runs during upload; optional cleanup runs via WordPress Cron after URL verification. It may wait for site traffic or a configured cron runner.

== Changelog ==
= 0.9.0 =
Add default local-browser JPEG/PNG conversion for native admin multi-file media uploaders. Replace actual Plupload multipart sources before upload, serialize encoding, use workers with local-page fallback, validate dimensions and WebP output, preserve transparency and strip image metadata. Add clear progress/error reporting and a browser-conversion setting. Retain server conversion for upload paths outside Plupload. Test real media-dialog requests, actual saved files and thumbnail formats with WordPress and Chromium.
= 0.8.0 =
Add default WebP-only upload handling before attachment registration and thumbnail generation. Validate MIME and dimensions before removing newly uploaded JPEG/PNG. Protect colliding filenames, preserve transparent output, reject failed conversion, and expose a dedicated setting. Existing-media deletion safeguards remain intact.
= 0.7.1 =
Remove same-format engine, upload options, media controls and automatic fallback. Keep WebP-only conversion and verified original deletion. Disable legacy same-format automatic upload preferences until explicitly re-enabled for WebP.
= 0.7.0 =
Add preview and consent-based deletion of tracked Pixora WebP copies when verified JPEG/PNG originals remain. Restore attachment pointers before deletion; preserve known references and retry failed deletions. Keep restored-copy history. Add four native Media Library list-view bulk actions with owner-scoped, selected-only resumable jobs and strict WebP/original deletion mode. Untracked copies and original WebP uploads are excluded; external references cannot be exhaustively detected.
= 0.6.1 =
Automatically fall back from failed WebP reclamation to smaller same-format replacement. Apply the fallback to cleanup buttons, combined batches and opted-in automatic uploads. Report measured net image-byte savings, including newly created WebP cost; never claim success for zero gain.
= 0.6.0 =
Add RTL media gallery, stored-file counts, resumable duplicate scanning, global byte/pixel duplicate groups and bounded cross-index visual similarity candidates. Explicit automatic-upload/storage status, cache invalidation on media changes, and real WordPress/Chromium tests. Duplicate discovery never deletes media.
= 0.5.0 =
Add same-format JPEG/PNG atomic replacement for actual disk reduction without HTTP routing. Preserve file names, MIME, dimensions and existing URLs; skip non-smaller outputs and unchanged previously optimized files. Separate opt-in for automatic same-format uploads. Tested with a real Nginx static frontend that ignores .htaccess. See docs/RESEARCH.md in the source repository for reviewed approaches and evidence.
= 0.4.2 =
Pause bulk jobs on shared routing/preflight failures and preserve the failed image for retry. Hosting delivery issues still require successful verification.
= 0.4.1 =
Preflight image routing before disk-oriented conversion. Explicit temporary redirects to WebP avoid legacy extension MIME confusion. Preserve originals when exact response verification fails.
= 0.4.0 =
One-step optimization and reclamation, opt-in upload cleanup queue, per-image cleanup/restore and status, quality presets, distinct partial/failure outcomes, copied reports, Persian filename routing and full-resolution WordPress original mapping. Real WordPress integration suite added.
= 0.3.4 =
Eligible attachment queries and canonical URL redirect verification.
= 0.3.3 =
Image memory release and PHP fatal diagnostics.
= 0.3.2 =
Process-owned locks, legacy lock recovery and resumable processing.
= 0.3.1 =
Always-visible deletion controls with host support explanation.
= 0.3.0 =
Verified optional disk reclamation.
= 0.2.0 =
Persian standalone dashboard and media controls.
= 0.1.0 =
Initial development release.
