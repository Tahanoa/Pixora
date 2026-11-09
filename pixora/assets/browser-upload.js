/* global plupload, mOxie, pixoraBrowser, PixoraCodec */
(() => {
 'use strict';
 if (!window.plupload || !window.pixoraBrowser || !pixoraBrowser.enabled) return;
 let chain = Promise.resolve(), pending = 0, completed = 0, saved = 0, panel;
 const format = bytes => new Intl.NumberFormat('fa-IR', { maximumFractionDigits: 2 }).format(bytes / 1048576) + ' مگابایت';
 function announce(text, error = false) {
  if (!panel) {
   panel = document.createElement('div'); panel.className = 'pixora-browser-status'; panel.dir = 'rtl';
   panel.setAttribute('role', 'status'); panel.setAttribute('aria-live', 'polite');
   const close = document.createElement('button'); close.type = 'button'; close.textContent = 'بستن';
   close.addEventListener('click', () => { panel.hidden = true; });
   panel.append(document.createElement('span'), close); document.body.append(panel);
  }
  panel.hidden = false; panel.classList.toggle('pixora-browser-error', error); panel.firstChild.textContent = text;
 }
 function workerConvert(file) {
  if (!window.Worker || !window.OffscreenCanvas || !window.createImageBitmap) return PixoraCodec.convert(file, pixoraBrowser.quality);
  return new Promise((resolve, reject) => {
   let worker;
   try { worker = new Worker(pixoraBrowser.worker); }
   catch { PixoraCodec.convert(file, pixoraBrowser.quality).then(resolve, reject); return; }
   const timer = setTimeout(() => { worker.terminate(); reject(new Error('تبدیل تصویر بیش از حد طول کشید؛ تصویر کوچک‌تری انتخاب کنید.')); }, 90000);
   worker.onmessage = event => {
    clearTimeout(timer); worker.terminate();
    if (event.data.error) reject(new Error(event.data.error)); else resolve(event.data.result);
   };
   worker.onerror = () => {
    clearTimeout(timer); worker.terminate();
    // CSP or unsupported worker initialization: encoding is still local in the page.
    PixoraCodec.convert(file, pixoraBrowser.quality).then(resolve, reject);
   };
   worker.postMessage({ file, quality: pixoraBrowser.quality });
  });
 }
 window.addEventListener('beforeunload', event => { if (pending) { event.preventDefault(); event.returnValue = ''; } });
 plupload.addFileFilter('pixora_webp', function(enabled, file, callback) {
  if (!enabled) { callback(true); return; }
  const limit = plupload.parseSize(enabled.max_file_size || 0);
  if (file.pixoraConverted || !(/\.(jpe?g|png)$/i.test(file.name) || /image\/(jpeg|png)/.test(file.type))) {
   if (limit && file.size > limit) {
    this.trigger('Error', { code: plupload.FILE_SIZE_ERROR, message: 'حجم فایل از حد مجاز آپلود هاست بیشتر است.', file }); callback(false);
   } else callback(true);
   return;
  }
  const uploader = this;
  ++pending; announce(`Pixora · ${pending.toLocaleString('fa-IR')} تصویر در صف تبدیل مرورگر`);
  // A shared queue bounds decoded memory across every media dialog on the page.
  chain = chain.catch(() => {}).then(async () => {
   announce(`Pixora · در حال تبدیل در مرورگر: ${file.name}`);
   const native = file.getNative && file.getNative();
   if (!native) throw new Error('این مسیر آپلود از تبدیل مرورگری پشتیبانی نمی‌کند؛ از کتابخانهٔ رسانه در مرورگر جدید استفاده کنید.');
   const result = await workerConvert(native);
   if (limit && result.after > limit) throw new Error('WebP آماده شد اما از حد مجاز آپلود هاست بزرگ‌تر است؛ کیفیت پایین‌تری انتخاب کنید.');
   const name = file.name.replace(/\.[^.]+$/, '') + '.webp';
   const nativeWebp = new File([result.blob], name, { type: 'image/webp', lastModified: native.lastModified || Date.now() });
   // Preserve the queue ID while replacing the actual multipart source, not just its extension.
   const replacement = new plupload.File(new mOxie.File(file.getSource().ruid, nativeWebp));
   file.destroy();
   file.getSource = replacement.getSource.bind(replacement);
   file.getNative = replacement.getNative.bind(replacement);
   file.destroy = replacement.destroy.bind(replacement);
   file.name = name; file.type = 'image/webp'; file.size = nativeWebp.size;
   file.origSize = nativeWebp.size; file.pixoraConverted = true;
   ++completed; saved += result.before - result.after;
   announce(`Pixora · ${name} در مرورگر آماده شد؛ ${format(result.before)} ← ${format(result.after)} · ${completed.toLocaleString('fa-IR')} تصویر آمادهٔ ارسال. ${result.after > result.before ? 'این خروجی کوچک‌تر نشد. ' : ''}${saved >= 0 ? 'کاهش' : 'افزایش'} خالص حجم پیش از ارسال: ${format(Math.abs(saved))}`);
   callback(true);
  }).catch(error => {
   file.status = plupload.FAILED;
   announce(`Pixora · ${file.name}: ${error.message}`, true);
   uploader.trigger('Error', { code: plupload.FILE_EXTENSION_ERROR, message: `Pixora: ${error.message}`, file });
   callback(false); // Never silently send the original after a failed browser conversion.
  }).finally(() => { --pending; });
 });
})();
