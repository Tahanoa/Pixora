/* global pixora */
(() => {
 'use strict';
 document.addEventListener('click', async event => {
  const button = event.target.closest('[data-pixora-id]');
  if (!button) return;
  event.preventDefault();
  event.stopPropagation();
  if (button.disabled) return;
  const label = button.textContent;
  const status = button.nextElementSibling;
  button.disabled = true;
  button.textContent = pixora.working;
  if (status) status.textContent = '';
  try {
   const body = new URLSearchParams({ action: 'pixora_optimize', nonce: pixora.nonce, id: button.dataset.pixoraId });
   const response = await fetch(pixora.url, { method: 'POST', credentials: 'same-origin', body });
   if (!response.ok) throw new Error(pixora.failed);
   const result = await response.json();
   if (!result.success) throw new Error(result.data?.message || pixora.failed);
   button.textContent = pixora.optimized;
   if (status) status.textContent = result.data.message;
  } catch (error) {
   button.disabled = false;
   button.textContent = label;
   if (status) status.textContent = error.message;
  }
 });
 const buttons = document.querySelectorAll('[data-pixora]');
 const stop = document.getElementById('pixora-stop');
 const progress = document.getElementById('pixora-progress');
 const log = document.getElementById('pixora-log');
 if (!stop) return;
 let stopped = false;
 stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
 buttons.forEach(button => button.addEventListener('click', async () => {
  const reclaim = button.dataset.pixora === 'reclaim';
  if (reclaim && !document.getElementById('pixora-cleanup-consent')?.checked) {
   progress.textContent = pixora.consent;
   return;
  }
  stopped = false;
  buttons.forEach(item => { item.disabled = true; });
  stop.disabled = false;
  let cursor = 0, processed = 0, skipped = 0, saved = 0, freed = 0, remaining = 0;
  log.textContent = '';
  progress.textContent = pixora.working;
  try {
   while (!stopped) {
    const body = new URLSearchParams({ action: 'pixora_batch', nonce: pixora.nonce, mode: button.dataset.pixora, cursor: String(cursor), consent: reclaim ? 'delete-originals' : '' });
    const response = await fetch(pixora.url, { method: 'POST', credentials: 'same-origin', body });
    if (!response.ok) throw new Error(pixora.failed);
    const result = await response.json();
    if (!result.success) throw new Error(result.data?.message || pixora.failed);
    if (result.data.done) { progress.textContent += ` — ${pixora.finished}`; break; }
    cursor = result.data.cursor;
    if (result.data.status === 'processed') processed++; else skipped++;
    saved += result.data.saved;
    freed += result.data.freed || 0;
    remaining += result.data.remaining || 0;
    progress.textContent = `${pixora.processed}: ${processed.toLocaleString('fa-IR')} | ${pixora.skipped}: ${skipped.toLocaleString('fa-IR')} | ${((reclaim ? freed : saved) / 1048576).toLocaleString('fa-IR', { maximumFractionDigits: 2 })} ${reclaim ? pixora.freed : pixora.saved}${reclaim && remaining ? ` | ${pixora.remaining}: ${remaining.toLocaleString('fa-IR')}` : ''}`;
    log.textContent = (`#${cursor}: ${result.data.message}\n` + log.textContent).slice(0, 20000);
   }
   if (stopped) progress.textContent += ` — ${pixora.stopped}`;
  } catch (error) { log.textContent = `${error.message}\n${log.textContent}`; }
  finally { buttons.forEach(item => { item.disabled = false; }); stop.disabled = true; }
 }));
})();
