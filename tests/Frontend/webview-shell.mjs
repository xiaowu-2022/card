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
test('selects the fastest verified host even when a slower cached host remains available',async()=>{
 const result=await discover([a,b],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  if(host===b)await new Promise(r=>setTimeout(r,20));return reply([a,b]);
 });assert.equal(result.selected,a);
});
test('recovers unavailable seeds using a verified cached domain',async()=>{
 const result=await discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async host=>{
  if(host===a)throw Error('offline');return reply([b]);
 });assert.equal(result.selected,b);
});
test('measures a newly discovered domain before selecting it over cached and seed hosts',async()=>{
 const calls=[];
 const result=await discover([a],'company',{tenantId:'tenant',origins:[a,b],selected:b},async host=>{
  calls.push(host);
  await new Promise(resolve=>setTimeout(resolve,host===c?1:30));
  return reply([a,b,c]);
 });
 assert.equal(result.selected,c);assert.deepEqual(calls.sort(),[a,b,c]);
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

// Exercise native event ordering, including a document whose JS never renders.
test('native loaded cannot dismiss welcome; only current rendered content can reveal full-size child', async () => {
 const {runInNewContext} = await import('node:vm');
 const readinessJs = ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/readiness.ts','utf8'), {compilerOptions:{module:ts.ModuleKind.ES2022}}).outputText;
 const debugJs = ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/debug.ts','utf8'), {compilerOptions:{module:ts.ModuleKind.ES2022}}).outputText;
 const {safeAddress,debugScript,debugMessages} = await import('data:text/javascript;base64,'+Buffer.from(debugJs).toString('base64'));
 const {readinessScript} = await import('data:text/javascript;base64,'+Buffer.from(readinessJs).toString('base64'));
 let script = readFileSync('mobile/webview-shell/src/pages/index/index.vue', 'utf8').split('<script setup lang="ts">')[1].split('</script>')[0];
 script = script.replace(/^import .*;\n/gm, '').replace(/\/\/ #ifndef APP-PLUS[\s\S]*?\/\/ #endif/g, '');
 const requests = [], windows = [], ticks = [], timers = new Map(); let sequence = 0;
 let finishDiscovery;
 let parentHeight = 828;
 const flushLayout = () => { while (ticks.length) ticks.shift()(); };
 const context = {
  nextTick: callback => { ticks.push(callback); },
  discover: () => new Promise(resolve => { finishDiscovery = resolve; }),
  safeAddress, debugScript, debugMessages, readinessScript, ref: value => ({value}), config: JSON.parse(readFileSync('mobile/webview-shell/src/config.json', 'utf8')),
  uni: {createSelectorQuery: () => { let callback; const query = {select:()=>query,boundingClientRect:fn=>{callback=fn;return query;},exec:()=>callback({height:parentHeight})};return query;},getStorageSync: () => ({tenantId:'tenant'}), request: request => requests.push(request),getSystemInfoSync: () => ({statusBarHeight: 24, windowHeight: 0}),onWindowResize: () => {},offWindowResize: () => {}},
  plus: {webview: {create: (url,id,styles) => {
   const view = {events:{}, styles:[styles], url:'', scripts:[], closed:false,
    addEventListener(name,fn){this.events[name]=fn;},getURL(){return this.url;},setStyle(s){this.styles.push(s);},
    evalJS(s){this.scripts.push(s);},loadURL(url){this.requestedURL=url;},close(){this.closed=true;},
    show(){assert.fail('no independent show');},hide(){assert.fail('no independent hide');}};
   windows.push(view);return view;
  }}},
  getCurrentPages: () => [{$getAppWebview: () => ({append: () => {}})}],
  onReady: () => {}, onShow: () => {}, onHide: () => {}, onUnload: () => {}, onBackPress: () => {},
  setTimeout: (fn,delay) => {const id=++sequence;timers.set(id,{fn,delay});return id;},
  clearTimeout: id => timers.delete(id),
 };
 const js = ts.transpileModule(script + '\nglobalThis.pageTest = {open, retry, state, active, token:()=>readinessToken,resizeContent,refreshDebug,debugEnabled,debugRows};', {compilerOptions: {target: ts.ScriptTarget.ES2022}}).outputText;
 runInNewContext(js, context);
 const page = context.pageTest;page.open(a);page.active.value=a;
 const view=windows[0];
 assert.equal(view.requestedURL,a+'/#/pages/login/index');
 assert.equal(view.styles[0].bottom,'0px');assert.equal(view.styles[0].height,undefined);
 assert.equal(view.styles[0].position,'absolute');
 assert.equal(view.styles[0].opacity,0);assert.equal(view.styles[0].render,'always');
 view.events.loaded();assert.equal(view.scripts.length,0);
 view.url=a+'/';view.events.loaded();assert.equal(page.state.value,'loading');assert.equal(view.scripts.length,1);
 const oldToken=page.token();
 view.events.titleUpdate({title:'Spec Pay'});assert.equal(page.state.value,'loading');
 [...timers.values()].find(t=>t.delay===30000).fn();assert.equal(page.state.value,'error');assert.equal(view.closed,true);
 view.events.titleUpdate({title:oldToken});assert.equal(page.state.value,'error');
 const reconnect = page.retry();
 assert.equal(page.state.value,'discovering');assert.equal(windows.length,1);
 finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b,fetchedAt:Date.now()});
 await reconnect;
 const retry=windows[1];assert.equal(retry.requestedURL,b+'/#/pages/login/index');
 retry.url=b+'/';retry.events.loaded();
 retry.events.titleUpdate({title:oldToken});assert.equal(page.state.value,'loading');
 retry.events.titleUpdate({title:page.token()});assert.equal(page.state.value,'loading');
 assert.equal(retry.styles.at(-1).opacity,0, 'welcome remains during startup pulse');
 flushLayout();
 assert.equal(page.debugEnabled.value,false);
 assert.equal(page.state.value,'loading');
 assert.equal(retry.styles.at(-1).height,'504px', 'actual native window shrinks with debug disabled');
 assert.equal(retry.styles.at(-1).opacity,0);
 const pulse = [...timers.values()].find(t=>t.delay===120);
 assert.ok(pulse);
 pulse.fn(); flushLayout();
 assert.equal(page.state.value,'ready');
 assert.equal(retry.styles.at(-1).opacity,1);
 assert.equal(retry.styles.at(-1).height,'804px');
 parentHeight=500; page.resizeContent(); flushLayout();
 assert.equal(retry.styles.at(-1).height,'476px');
 parentHeight=0; page.resizeContent(); flushLayout();
 assert.equal(retry.styles.at(-1).height,'476px', 'zero measurements cannot collapse the child');
 parentHeight=828; page.resizeContent(); flushLayout();
 assert.equal(retry.styles.at(-1).height,'804px');
 assert.equal([...timers.values()].some(t=>t.delay===30000 || t.delay===350),false);
 page.refreshDebug();
 requests.at(-1).success({statusCode:200,data:{tenantId:'tenant',tenantSlug:'tenant-a',enabled:true}});
 flushLayout();
 assert.equal(page.debugEnabled.value,true);assert.equal(retry.styles.at(-1).bottom,'300px');
 assert.equal(retry.styles.at(-1).height,'504px');
 assert.ok(retry.scripts.at(-1).includes('__specpayDiagnostics'));
 page.refreshDebug();
 requests.at(-1).success({statusCode:200,data:{tenantId:'other',tenantSlug:'tenant-a',enabled:true}});
 flushLayout();
 assert.equal(page.debugEnabled.value,false);assert.equal(page.debugRows.value.length,0);assert.equal(retry.styles.at(-1).bottom,'0px');
 assert.equal(retry.styles.at(-1).height,'804px');
 page.refreshDebug();requests.at(-1).success({statusCode:200,data:{tenantId:'tenant',tenantSlug:'tenant-a',enabled:true}});
 page.refreshDebug();const oldRequest=requests.at(-1);page.refreshDebug();
 oldRequest.success({statusCode:200,data:{tenantId:'tenant',tenantSlug:'tenant-a',enabled:false}});
 assert.equal(page.debugEnabled.value,true);
 requests.at(-1).fail();assert.equal(page.debugEnabled.value,false);assert.equal(page.debugRows.value.length,0);

 page.open(a);
 const stale=windows.at(-1);stale.url=a+'/';
 stale.events.titleUpdate({title:page.token()});
 page.open(b);
 flushLayout();
 assert.ok(stale.closed);
 assert.equal(stale.styles.some(style=>style.opacity===1),false, 'replaced window cannot be revealed by a pending layout callback');

 const current=windows.at(-1);current.url=b+'/';
 current.events.titleUpdate({title:page.token()});flushLayout();
 assert.equal(page.state.value,'ready');
 assert.equal(current.styles.at(-1).height,'804px');
 assert.equal(current.styles.some(style=>style.height==='504px'),false, 'retry cannot repeat startup pulse');
 pulse.fn();flushLayout();
 assert.equal(current.styles.at(-1).opacity,1, 'stale pulse callback has no effect');

});
