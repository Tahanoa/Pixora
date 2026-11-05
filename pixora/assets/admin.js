/* global pixora */
(() => {
 'use strict';
 const buttons = document.querySelectorAll('[data-pixora]');
 const stop = document.getElementById('pixora-stop');
 const progress = document.getElementById('pixora-progress');
 const log = document.getElementById('pixora-log');
 if (!stop) return;
 let stopped = false;
 stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
 buttons.forEach(button => button.addEventListener('click', async () => {
  stopped = false;
  buttons.forEach(item => { item.disabled = true; });
  stop.disabled = false;
  let cursor = 0, processed = 0, skipped = 0, saved = 0;
  log.textContent = '';
  try {
   while (!stopped) {
    const body = new URLSearchParams({ action: 'pixora_batch', nonce: pixora.nonce, mode: button.dataset.pixora, cursor: String(cursor) });
    const response = await fetch(pixora.url, { method: 'POST', credentials: 'same-origin', body });
    if (!response.ok) throw new Error(pixora.failed);
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || pixora.failed);
    if (result.data.done) { progress.textContent += ` — ${pixora.finished}`; break; }
    cursor = result.data.cursor;
    if (result.data.status === 'processed') processed++; else skipped++;
    saved += result.data.saved;
    progress.textContent = `${processed} ✓ | ${skipped} ↷ | ${(saved / 1048576).toFixed(2)} MB ↓`;
    log.textContent = (`#${cursor}: ${result.data.message}\n` + log.textContent).slice(0, 20000);
   }
  } catch (error) { log.textContent = `${error.message}\n${log.textContent}`; }
  finally { buttons.forEach(item => { item.disabled = false; }); stop.disabled = true; }
 }));
})();
