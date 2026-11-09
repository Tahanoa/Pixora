const { chromium } = require('playwright');
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
(async () => {
 const html = process.argv[2];
 if (!html) throw new Error('Pass the exported test-only WordPress gallery HTML path');
 const browser = await chromium.launch({ executablePath: process.env.PIXORA_CHROMIUM || '/usr/bin/chromium', headless: true, args: ['--no-sandbox'] });
 try {
  const page = await browser.newPage({ viewport: { width: 1280, height: 1000 } });
  await page.setContent(fs.readFileSync(path.resolve(html), 'utf8'), { waitUntil: 'load' });
  await page.locator('.pixora-gallery-grid img').evaluateAll(images => Promise.all(images.map(async image => { image.loading = 'eager'; await image.decode().catch(() => {}); })));
  const broken = await page.locator('.pixora-gallery-grid img').evaluateAll(images => images.filter(image => !image.complete || !image.naturalWidth).length);
  assert.equal(broken, 0, 'Gallery preview thumbnails must decode');
  assert.ok(await page.locator('.pixora-gallery-item').count() >= 5);
  assert.equal(await page.locator('.pixora-app').getAttribute('dir'), 'rtl');
  await page.evaluate(() => {
   window.pixora = { url: '/test-only-ajax', nonce: 'test-only' };
   window.fetch = async () => ({ ok: true, json: async () => ({ success: true, data: { done: true } }) });
  });
  await page.addScriptTag({ path: path.join(__dirname, '../pixora/assets/gallery.js') });
  await page.locator('#pixora-scan').click();
  await page.locator('#pixora-scan-status').filter({ hasText: 'بررسی تمام شد' }).waitFor();
  await page.locator('#pixora-scan-reset').click();
  assert.match(await page.locator('#pixora-scan-status').textContent(), /آماده/);
  const output = process.env.PIXORA_SCREENSHOT || '/tmp/pixora-gallery-preview.png';
  await page.screenshot({ path: output, fullPage: true });
  await page.setViewportSize({ width: 390, height: 844 });
  const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 2);
  assert.equal(overflow, false, 'Mobile gallery must not overflow horizontally');
  await page.screenshot({ path: output.replace('.png', '-mobile.png'), fullPage: true });
  console.log('PASS: real Chromium thumbnails, RTL layout, scan/reset interaction and mobile width');
 } finally { await browser.close(); }
})().catch(error => { console.error(error); process.exitCode = 1; });
