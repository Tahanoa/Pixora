/* global pixora */
(() => {
 'use strict';
 const start = document.getElementById('pixora-scan');
 if (!start) return;
 const stop = document.getElementById('pixora-scan-stop');
 const reset = document.getElementById('pixora-scan-reset');
 const status = document.getElementById('pixora-scan-status');
 const key = `pixora-scan:${pixora.url}`;
 let cursor = 0, stopped = false;
 try { cursor = Number(sessionStorage.getItem(key)) || 0; } catch { /* Continue in memory. */ }
 const persist = () => { try { sessionStorage.setItem(key, String(cursor)); } catch { /* Continue in memory. */ } };
 stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
 reset.addEventListener('click', () => { cursor = 0; persist(); status.textContent = 'آمادهٔ بررسی دوباره؛ دکمهٔ بررسی را بزنید.'; });
 start.addEventListener('click', async () => {
  start.disabled = true; reset.disabled = true; stop.disabled = false; stopped = false;
  try {
   while (!stopped) {
    status.textContent = `در حال بررسی پس از شناسهٔ ${cursor.toLocaleString('fa-IR')}…`;
    const response = await fetch(pixora.url, { method: 'POST', credentials: 'same-origin', body: new URLSearchParams({ action: 'pixora_scan', nonce: pixora.nonce, cursor: String(cursor) }) });
    if (!response.ok) throw new Error(`خطای HTTP ${response.status}؛ بررسی متوقف شد. پس از رفع خطا همان دکمه را بزنید.`);
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || 'بررسی ناموفق بود.');
    if (result.data.done) { cursor = 0; persist(); status.textContent = 'بررسی تمام شد؛ برای مشاهدهٔ نتیجه‌های جدید صفحه را تازه کنید.'; return; }
    cursor = result.data.cursor; persist();
    status.textContent = `#${cursor}: ${result.data.message}`;
   }
   status.textContent += ' — متوقف شد؛ می‌توانید ادامه دهید.';
  } catch (error) { status.textContent = error.message; }
  finally { start.disabled = false; reset.disabled = false; stop.disabled = true; }
 });
})();
