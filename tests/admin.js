const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const node = dataset => ({ dataset, disabled: false, textContent: '', listeners: {}, addEventListener(type, callback) { this.listeners[type] = callback; } });
const optimize = node({ pixora: 'optimize' });
const reclaim = node({ pixora: 'reclaim' });
const combined = node({ pixora: 'optimize_reclaim' });
const inplace = node({ pixora: 'inplace' });
let delegated, confirmAllowed = true;
const elements = { 'pixora-stop': node({}), 'pixora-progress': node({}), 'pixora-log': node({}), 'pixora-reset': node({}), 'pixora-cleanup-consent': { checked: false } };
const storage = new Map();
let queue = [], cursors = [], payloads = [];
const context = {
 URLSearchParams, console, window: { confirm: () => confirmAllowed },
 pixora: { url: '/admin-ajax.php', nonce: 'test', modeOptimize: 'optimize', modeReclaim: 'reclaim', modeCombined: 'combined', partialCount: 'partial', working: 'working', finished: 'done', consent: 'consent', resume: 'resume' },
 document: { addEventListener(type, listener) { delegated = listener; }, querySelectorAll(selector) { return selector === '[data-pixora]' ? [optimize, reclaim, combined, inplace] : []; }, getElementById(id) { return elements[id]; } },
 sessionStorage: { getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key) },
 fetch: async (url, options) => {
  cursors.push(options.body.get('cursor'));
  payloads.push(Object.fromEntries(options.body));
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
 elements['pixora-cleanup-consent'].checked = true;
 queue = [json({ cursor: 20, status: 'partial', saved: 0, freed: 0, message: 'WebP ready; original retained' }), json({ done: true })];
 await combined.listeners.click();
 assert.equal(payloads.at(-1).mode, 'optimize_reclaim');
 assert.equal(payloads.at(-1).consent, 'delete-originals');
 assert.match(elements['pixora-progress'].textContent, /partial: ۱/);
 queue = [json({ cursor: 42, halt: true, status: 'partial', message: 'routing failed' })];
 await combined.listeners.click();
 assert.equal(queue.length, 0);
 assert.match(elements['pixora-progress'].textContent, /متوقف/);
 const haltedCursor = payloads.at(-1).cursor;
 queue = [json({ done: true })];
 await combined.listeners.click();
 assert.equal(payloads.at(-1).cursor, haltedCursor);
 const resultNode = node({});
 const sibling = node({}); sibling.textContent = 'other action';
 const media = node({ pixoraId: '42', pixoraMode: 'optimize_reclaim' });
 const container = { querySelector: () => resultNode, querySelectorAll: () => [media, sibling], outerHTML: '' };
 media.closest = () => container;
 const event = { target: { closest: () => media }, preventDefault() {}, stopPropagation() {} };
 confirmAllowed = false;
 const requestsBefore = payloads.length;
 await delegated(event);
 assert.equal(payloads.length, requestsBefore);
 confirmAllowed = true;
 queue = [json({ message: 'done', html: '<span>updated media status</span>' })];
 await delegated(event);
 assert.equal(payloads.at(-1).mode, 'optimize_reclaim');
 assert.equal(container.outerHTML, '<span>updated media status</span>');
 assert.equal(sibling.textContent, 'other action');
 console.log('PASS: HTTP diagnostics, saved cursor, resumption, consent, expired nonce, memory error and reset');
})().catch(error => { console.error(error); process.exitCode = 1; });
