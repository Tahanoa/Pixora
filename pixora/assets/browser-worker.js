/* Dedicated local worker: one image at a time. */
importScripts(`browser-codec.js${self.location.search}`);
self.onmessage = async event => {
 try {
  const result = await self.PixoraCodec.convert(event.data.file, event.data.quality);
  self.postMessage({ result });
 } catch (error) { self.postMessage({ error: error.message || 'تبدیل تصویر در مرورگر ناموفق بود.' }); }
};
