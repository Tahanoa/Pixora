const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const node = dataset => ({ dataset, disabled: false, textContent: '', listeners: {}, addEventListener(type, callback) { this.listeners[type] = callback; } });
const optimize = node({ pixora: 'optimize' });
const reclaim = node({ pixora: 'reclaim' });
const elements = { 'pixora-stop': node({}), 'pixora-progress': node({}), 'pixora-log': node({}), 'pixora-reset': node({}), 'pixora-cleanup-consent': { checked: false } };
const storage = new Map();
let queue = [], cursors = [];
const context = {
 URLSearchParams, console,
 pixora: { url: '/admin-ajax.php', nonce: 'test', modeOptimize: 'optimize', modeReclaim: 'reclaim', working: 'working', finished: 'done', consent: 'consent', resume: 'resume' },
 document: { addEventListener() {}, querySelectorAll() { return [optimize, reclaim]; }, getElementById(id) { return elements[id]; } },
 sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
 fetch: async (url, options) => {
  cursors.push(options.body.get('cursor'));
  assert.ok(queue.length, 'Unexpected request');
  const response = queue.shift();
  return { ok: response.status === 200, status: response.status, text: async () => response.text };
 }
};
vm.createContext(context);
vm.runInContext(fs.readFileSync(__dirname + '/../pixora/assets/admin.js', 'utf8'), context);
const json = data => ({ status: 200, text: JSON.stringify({ success: true, data }) });
(async () => {
 queue = [json({ cursor: 10, status: 'processed', saved: 1024, message: 'ok' }), { status: 504, text: '<html>timeout</html>' }];
 await optimize.listeners.click();
 assert.deepEqual(cursors, ['0', '10']);
 assert.match(elements['pixora-log'].textContent, /HTTP 504/);
 assert.ok(storage.size === 1);
 assert.equal(optimize.disabled, false);
 queue = [json({ done: true })];
 await optimize.listeners.click();
 assert.equal(cursors.at(-1), '10');
 assert.equal(storage.size, 0);
 const before = cursors.length;
 await reclaim.listeners.click();
 assert.equal(cursors.length, before);
 assert.equal(elements['pixora-progress'].textContent, 'consent');
 queue = [{ status: 500, text: JSON.stringify({ success: false, data: { message: 'attachment #7890: PHP image stage failed' } }) }];
 await optimize.listeners.click();
 assert.match(elements['pixora-log'].textContent, /#7890/);
 queue = [{ status: 403, text: '-1' }];
 await optimize.listeners.click();
 assert.match(elements['pixora-log'].textContent, /منقضی/);
 queue = [{ status: 200, text: 'Fatal error: Allowed memory size exhausted' }];
 await optimize.listeners.click();
 assert.match(elements['pixora-log'].textContent, /حافظه/);
 elements['pixora-reset'].listeners.click();
 assert.equal(storage.size, 0);
 console.log('PASS: HTTP diagnostics, saved cursor, resumption, consent, expired nonce, memory error and reset');
})().catch(error => { console.error(error); process.exitCode = 1; });
