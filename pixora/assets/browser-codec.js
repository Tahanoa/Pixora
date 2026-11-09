/* Local browser encoder. No network requests, remote services or source uploads. */
(() => {
 'use strict';
 const MAX_BYTES = 64 * 1024 * 1024;
 const MAX_PIXELS = 24000000;
 function fail(message) { throw new Error(message); }
 async function inspect(file) {
  if (!file.size || file.size > MAX_BYTES) fail('تصویر برای تبدیل مرورگری باید حداکثر ۶۴ مگابایت باشد.');
  const bytes = new Uint8Array(await file.slice(0, 2 * 1024 * 1024).arrayBuffer());
  const view = new DataView(bytes.buffer);
  let width, height;
  if (bytes[0] === 0x89 && String.fromCharCode(...bytes.slice(1, 4)) === 'PNG') {
   if (bytes.length < 33 || view.getUint32(4) !== 0x0d0a1a0a || view.getUint32(8) !== 13 || view.getUint32(12) !== 0x49484452) fail('ساختار PNG معتبر نیست.');
   width = view.getUint32(16); height = view.getUint32(20);
   let offset = 8, reachedData = false;
   while (offset + 8 <= bytes.length) {
    const size = view.getUint32(offset), type = view.getUint32(offset + 4);
    if (type === 0x6163544c) fail('PNG متحرک برای حفظ حرکت تبدیل نمی‌شود؛ نسخهٔ WebP متحرک را بارگذاری کنید.');
    if (type === 0x49444154) { reachedData = true; break; }
    offset += size + 12;
   }
   if (!reachedData) fail('بررسی امن ساختار PNG ممکن نشد.');
  } else if (bytes[0] === 0xff && bytes[1] === 0xd8) {
   let offset = 2;
   while (offset + 4 <= bytes.length) {
    if (bytes[offset++] !== 0xff) fail('ساختار JPEG معتبر نیست.');
    while (bytes[offset] === 0xff) ++offset;
    const marker = bytes[offset++];
    if (marker === 0xda || marker === 0xd9) break;
    if (marker === 0x01 || (marker >= 0xd0 && marker <= 0xd7)) continue;
    if (offset + 2 > bytes.length) break;
    const size = view.getUint16(offset);
    if (size < 2) fail('ساختار JPEG معتبر نیست.');
    if ([0xc0, 0xc1, 0xc2, 0xc3, 0xc5, 0xc6, 0xc7, 0xc9, 0xca, 0xcb, 0xcd, 0xce, 0xcf].includes(marker) && offset + 7 <= bytes.length) {
     height = view.getUint16(offset + 3); width = view.getUint16(offset + 5); break;
    }
    offset += size;
   }
  } else fail('محتوای فایل JPEG یا PNG معتبر نیست.');
  if (!width || !height || width * height > MAX_PIXELS || width > 16383 || height > 16383) fail('تصویر بیش از ظرفیت تبدیل مرورگری است؛ حداکثر ۲۴ میلیون پیکسل و هر ضلع ۱۶۳۸۳ پیکسل.');
  return { width, height };
 }
 async function decode(file) {
  if (typeof createImageBitmap === 'function') return createImageBitmap(file, { imageOrientation: 'from-image' });
  if (typeof Image === 'undefined') fail('مرورگر از خواندن تصویر پشتیبانی نمی‌کند.');
  const url = URL.createObjectURL(file);
  try {
   const image = new Image(); image.src = url; await image.decode();
   return image;
  } finally { URL.revokeObjectURL(url); }
 }
 async function convert(file, quality = 82) {
  await inspect(file); // Check dimensions and animation before allocating decoded pixels.
  let image, canvas;
  try {
   image = await decode(file);
   const width = image.width || image.naturalWidth, height = image.height || image.naturalHeight;
   if (!width || !height || width * height > MAX_PIXELS || width > 16383 || height > 16383) fail('ابعاد تصویر برای تبدیل مرورگری مناسب نیست.');
   canvas = typeof OffscreenCanvas === 'function' ? new OffscreenCanvas(width, height) : document.createElement('canvas');
   canvas.width = width; canvas.height = height;
   const context = canvas.getContext('2d');
   if (!context) fail('حافظهٔ مرورگر برای پردازش تصویر کافی نیست.');
   context.drawImage(image, 0, 0);
   const settings = { type: 'image/webp', quality: Math.max(1, Math.min(100, Number(quality) || 82)) / 100 };
   const blob = canvas.convertToBlob ? await canvas.convertToBlob(settings) : await new Promise(resolve => canvas.toBlob(resolve, settings.type, settings.quality));
   if (!blob || blob.type !== 'image/webp' || !blob.size) fail('این مرورگر از ساخت WebP پشتیبانی نمی‌کند؛ مرورگر را به‌روز کنید.');
   const header = new Uint8Array(await blob.slice(0, 12).arrayBuffer());
   if (String.fromCharCode(...header.slice(0, 4)) !== 'RIFF' || String.fromCharCode(...header.slice(8, 12)) !== 'WEBP') fail('اعتبارسنجی WebP مرورگر ناموفق بود.');
   const verified = await decode(blob);
   try { if (verified.width !== width || verified.height !== height) fail('ابعاد خروجی WebP درست نیست.'); }
   finally { if (verified.close) verified.close(); }
   return { blob, width, height, before: file.size, after: blob.size };
  } finally {
   if (image && image.close) image.close();
   if (canvas) { canvas.width = 1; canvas.height = 1; }
  }
 }
 globalThis.PixoraCodec = { inspect, convert };
})();
