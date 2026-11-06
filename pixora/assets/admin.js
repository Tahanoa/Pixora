/* global pixora */
(() => {
 'use strict';
 async function request(body) {
  const response = await fetch(pixora.url, { method: 'POST', credentials: 'same-origin', body });
  const text = await response.text();
  if (response.status === 403 || text.trim() === '-1') throw new Error('اعتبار درخواست یا دسترسی منقضی شده است؛ صفحه را تازه کنید و ادامه دهید.');
  if (!response.ok) {
   let detail;
   try { detail = JSON.parse(text)?.data?.message; } catch { /* Do not expose raw HTML error pages. */ }
   throw new Error(detail || `خطای HTTP ${response.status}؛ هاست پاسخ خطای قابل‌خواندن نداد. error_log هاست را برای زمان این درخواست بررسی کنید.`);
  }
  let result;
  try { result = JSON.parse(text); } catch {
   const reason = /memory size/i.test(text) ? 'حافظهٔ PHP کافی نیست.' : /execution time/i.test(text) ? 'زمان اجرای PHP تمام شده است.' : 'پاسخ معتبر JSON از هاست دریافت نشد؛ گزارش خطای PHP هاست را بررسی کنید.';
   throw new Error(reason);
  }
  if (!result || !result.success) throw new Error(result?.data?.message || pixora.failed);
  return result.data;
 }
 document.querySelectorAll('[data-pixora-quality]').forEach(button => button.addEventListener('click', () => {
  const quality = document.getElementById('pixora-quality');
  if (quality) { quality.value = button.dataset.pixoraQuality; quality.focus(); }
 }));
 const jobKey = mode => `pixora:${pixora.url}:${mode}`;
 const jobs = {};
 function readJob(mode) {
  try { return jobs[mode] || JSON.parse(sessionStorage.getItem(jobKey(mode)) || 'null'); } catch { return jobs[mode] || null; }
 }
 function saveJob(mode, job) {
  jobs[mode] = job;
  try { if (job) sessionStorage.setItem(jobKey(mode), JSON.stringify(job)); else sessionStorage.removeItem(jobKey(mode)); } catch { /* In-memory continuation remains available. */ }
 }
 document.addEventListener('click', async event => {
  const button = event.target.closest('[data-pixora-id]');
  if (!button) return;
  event.preventDefault();
  event.stopPropagation();
  if (button.disabled) return;
  const mode = button.dataset.pixoraMode || 'optimize';
  const destructive = ['reclaim', 'optimize_reclaim', 'inplace'].includes(mode);
  if (destructive && !window.confirm(pixora.deleteConfirm)) return;
  const label = button.textContent;
  const container = button.closest('.pixora-media-tools');
  const status = container ? container.querySelector('.pixora-media-result') : button.nextElementSibling;
  const siblings = container ? container.querySelectorAll('button') : [button];
  siblings.forEach(item => { item.disabled = true; });
  button.disabled = true;
  button.textContent = pixora.working;
  if (status) status.textContent = '';
  try {
   const body = new URLSearchParams({ action: 'pixora_optimize', nonce: pixora.nonce, id: button.dataset.pixoraId, mode, consent: destructive ? 'delete-originals' : '' });
   const result = await request(body);
   const tools = button.closest('.pixora-media-tools');
   if (tools && result.html) { tools.outerHTML = result.html; return; }
   button.textContent = pixora.optimized;
   if (status) status.textContent = result.message;
  } catch (error) {
   siblings.forEach(item => { item.disabled = false; });
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
 const reset = document.getElementById('pixora-reset');
 const copy = document.getElementById('pixora-copy-log');
 copy?.addEventListener('click', async () => {
  try { await navigator.clipboard.writeText(`${progress.textContent}\n${log.textContent}`); copy.textContent = pixora.copied; }
  catch { copy.textContent = pixora.copyFailed; }
 });
 reset?.addEventListener('click', () => {
  ['optimize', 'restore', 'reclaim', 'optimize_reclaim', 'inplace'].forEach(mode => saveJob(mode, null));
  progress.textContent = 'پیشرفت ذخیره‌شده پاک شد؛ عملیات بعدی از ابتدا شروع می‌شود.';
 });
 stop.addEventListener('click', () => { stopped = true; stop.disabled = true; });
 buttons.forEach(button => button.addEventListener('click', async () => {
  const mode = button.dataset.pixora;
  const reclaim = ['reclaim', 'optimize_reclaim', 'inplace'].includes(mode);
  const modeLabel = { optimize: pixora.modeOptimize, restore: pixora.modeRestore, reclaim: pixora.modeReclaim, optimize_reclaim: pixora.modeCombined, inplace: pixora.modeInplace }[mode];
  if (reclaim && !document.getElementById('pixora-cleanup-consent')?.checked) {
   progress.textContent = pixora.consent;
   return;
  }
  stopped = false;
  buttons.forEach(item => { item.disabled = true; });
  stop.disabled = false;
  let { cursor = 0, processed = 0, skipped = 0, saved = 0, freed = 0, remaining = 0, failed = 0, partial = 0 } = readJob(mode) || {};
  if (reset) reset.disabled = true;
  log.textContent = '';
  progress.textContent = `${modeLabel} — ${cursor ? pixora.resume : pixora.working}`;
  try {
   while (!stopped) {
    const body = new URLSearchParams({ action: 'pixora_batch', nonce: pixora.nonce, mode: button.dataset.pixora, cursor: String(cursor), consent: reclaim ? 'delete-originals' : '' });
    const result = await request(body);
    if (result.done) { saveJob(mode, null); progress.textContent += ` — ${pixora.finished}`; break; }
    if (result.halt) {
     saveJob(mode, { cursor, processed, skipped, saved, freed, remaining, failed, partial });
     progress.textContent = `${modeLabel} — پردازش به علت مشکل بررسی مسیر متوقف شد؛ پس از رفع مشکل، همین دکمه را بزنید.`;
     log.textContent = (`#${result.cursor}: ${result.message}\nادامهٔ بررسی سایر تصاویر متوقف شد.\n` + log.textContent).slice(0, 20000);
     break;
    }
    cursor = result.cursor;
    if (result.scanning) { saveJob(mode, { cursor, processed, skipped, saved, freed, remaining, failed, partial }); continue; }
    if (result.status === 'processed') processed++;
    else if (result.status === 'failed') failed++;
    else if (result.status === 'partial') partial++;
    else skipped++;
    saved += result.saved;
    freed += result.freed || 0;
    remaining += result.remaining || 0;
    saveJob(mode, { cursor, processed, skipped, saved, freed, remaining, failed, partial });
    progress.textContent = `${modeLabel} | ${pixora.processed}: ${processed.toLocaleString('fa-IR')} | ${pixora.skipped}: ${skipped.toLocaleString('fa-IR')} | ${pixora.failedCount}: ${failed.toLocaleString('fa-IR')} | ${pixora.partialCount}: ${partial.toLocaleString('fa-IR')} | ${((reclaim ? freed : saved) / 1048576).toLocaleString('fa-IR', { maximumFractionDigits: 2 })} ${reclaim ? pixora.freed : pixora.saved}${reclaim && remaining ? ` | ${pixora.remaining}: ${remaining.toLocaleString('fa-IR')}` : ''}`;
    log.textContent = (`#${cursor}: ${result.message}\n` + log.textContent).slice(0, 20000);
   }
   if (stopped) progress.textContent += ` — ${pixora.stopped}`;
  } catch (error) { log.textContent = `${error.message}\nآخرین شناسهٔ تکمیل‌شده: ${cursor}؛ برای ادامه همان دکمه را بزنید.\n${log.textContent}`; }
  finally { buttons.forEach(item => { item.disabled = item.dataset.pixoraUnavailable === 'true'; }); stop.disabled = true; if (reset) reset.disabled = false; }
 }));
})();
