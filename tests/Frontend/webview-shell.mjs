import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const js = ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/directory.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ES2022,target:ts.ScriptTarget.ES2022}}).outputText;
const {discover,origin}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const a='https://a.example.com',b='https://b.example.com',c='https://c.example.com';
const reply=(origins,id='tenant',slug='company')=>({tenant:{id,slug},origins});
test('validates HTTPS candidates and excludes the static distribution host',()=>{
 for(const bad of ['http://a.example.com','https://a.example.com/x','https://u:p@a.example.com','javascript:alert(1)','https://zb33333.com'])assert.equal(origin(bad),null);
 assert.equal(origin(a),a);
});
test('downloads the authoritative list, probes newly discovered hosts and replaces old cache',async()=>{
 const calls=[];
 const result=await discover([a],'company',{tenantId:'tenant',origins:[a,b],selected:b},async host=>{
  calls.push(host);return reply([a,c]);
 });
 assert.deepEqual(result.origins,[a,c]);assert.ok([a,c].includes(result.selected));assert.ok(calls.includes(c));
});
test('keeps a verified previously selected host to preserve login cookies',async()=>{
 const result=await discover([a,b],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  if(host===b)await new Promise(r=>setTimeout(r,20));return reply([a,b]);
 });assert.equal(result.selected,b);
});
test('recovers unavailable seeds using a verified cached domain',async()=>{
 const result=await discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async host=>{
  if(host===a)throw Error('offline');return reply([b]);
 });assert.equal(result.selected,b);
});
test('rejects a different tenant, wrong company and malformed directories',async()=>{
 for(const data of [reply([a],'other'),reply([a],'tenant','other'),reply([b]),{}])
  await assert.rejects(discover([a],'company',{tenantId:'tenant'},async()=>data));
});
test('all-offline fails visibly instead of loading an unverified cached host',async()=>{
 await assert.rejects(discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async()=>{throw Error('offline')}),/连接服务器/);
});

test('a lost native callback cannot block a healthy line', async () => {
 const result = await discover([a,b], 'company', null,
  host => host === a ? new Promise(() => {}) : Promise.resolve(reply([a,b])),
  Date.now, {probeMs: 20, totalMs: 100});
 assert.equal(result.selected, b);
});
test('all hung callbacks release discovery so the UI can offer retry', async () => {
 await assert.rejects(discover([a,b], 'company', null, () => new Promise(() => {}),
  Date.now, {probeMs: 20, totalMs: 100}), /连接服务器/);
});
test('a large cached directory has one total deadline', async () => {
 const origins = Array.from({length: 100}, (_, i) => `https://host${i}.example.com`);
 const calls = [];
 await assert.rejects(discover([a], 'company', {origins}, host => {
  calls.push(host); return new Promise(() => {});
 }, Date.now, {probeMs: 100, totalMs: 20}), /连接服务器/);
 assert.ok(calls.length <= 6);
});

// Exercise the page's native event handling with an offline Webview adapter.
test('child window ignores empty loads, recovers after timeout and fills the area below the status bar', async () => {
 const {runInNewContext} = await import('node:vm');
 let script = readFileSync('mobile/webview-shell/src/pages/index/index.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
 script = script.replace(/^import .*;\n/gm, '').replace(/\/\/ #ifndef APP-PLUS[\s\S]*?\/\/ #endif/g, '');
 const hooks = {}, events = {}, styles = [];
 let loadedURL = '', timer, appended = false, reloaded = false;
 const view = {
  addEventListener: (name, fn) => { events[name] = fn; },
  getURL: () => loadedURL,
  setStyle: value => styles.push(value),
  loadURL: url => { assert.equal(url, a + '/#/pages/login/index'); }, reload: () => { reloaded = true; }, close: () => {},
  // Appended children must not be shown as independent windows.
  show: () => assert.fail('show() on appended child'), hide: () => assert.fail('hide() on appended child'),
 };
 const context = {
  ref: value => ({value}), config: JSON.parse(readFileSync('mobile/webview-shell/src/config.json', 'utf8')),
  uni: {getSystemInfoSync: () => ({statusBarHeight: 24, windowHeight: 800}),
   onWindowResize: () => {}, offWindowResize: () => {}},
  plus: {webview: {create: () => view}},
  getCurrentPages: () => [{$getAppWebview: () => ({append: child => {assert.equal(child, view); appended = true;}})}],
  onReady: fn => {hooks.ready = fn;}, onShow: () => {}, onUnload: () => {}, onBackPress: () => {},
  setTimeout: fn => {timer = fn; return 1;}, clearTimeout: () => {},
 };
 const js = ts.transpileModule(script + '\nglobalThis.pageTest = {open, retry, state, active};',
  {compilerOptions: {target: ts.ScriptTarget.ES2022}}).outputText;
 runInNewContext(js, context);
 const page = context.pageTest;
 page.open(a); page.active.value = a;
 assert.equal(appended, true);
 events.loaded(); assert.equal(page.state.value, 'loading');
 loadedURL = a + '/'; events.loaded();
 assert.equal(page.state.value, 'ready'); assert.equal(styles.at(-1).height, '776px');
 page.retry(); assert.equal(reloaded, true); timer();
 assert.equal(page.state.value, 'error'); assert.equal(styles.at(-1).height, '0px');
 events.loaded(); assert.equal(page.state.value, 'error');
 page.retry(); events.loaded(); assert.equal(page.state.value, 'ready');
});
