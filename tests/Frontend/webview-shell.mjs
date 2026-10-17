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
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('starts three probes and immediately selects the first valid response within 300ms, aborting the others',async()=>{
 let clock=0;const calls=[],aborted=[],pending=new Map();const d='https://d.example.com';
 const selection=discover([a,b,c,d],'company',null,(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));return new Promise(resolve=>pending.set(host,resolve));
 },()=>clock);
 await tick();assert.deepEqual(calls,[a,b,c]);
 clock=300;pending.get(b)(reply([a,b,c,d]));
 const result=await selection;
 assert.equal(result.selected,b);assert.deepEqual(result.origins,[a,b,c,d]);
 assert.deepEqual(aborted.sort(),[a,c]);assert.deepEqual(calls,[a,b,c]);
 pending.get(a)(reply([a,b,c,d],'other'));pending.get(c)(reply([a,b,c,d]));await tick();
 assert.equal(result.selected,b);assert.deepEqual(calls,[a,b,c]);
});
test('prioritizes the last cached candidate in the initial group but still freshly verifies it',async()=>{
 const calls=[];
 const result=await discover([a,b],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  calls.push(host);return reply([a,b]);
 });assert.equal(result.selected,b);assert.deepEqual(calls,[b,a]);
});
test('an unlisted cached selection is never added as a credential destination',async()=>{
 const calls=[];
 const result=await discover([a],'company',{tenantId:'tenant',selected:b,origins:[a]},async host=>{
  calls.push(host);return reply([a]);
 });assert.equal(result.selected,a);assert.deepEqual(calls,[a]);
});
test('skips a retired cached host and replaces the cache from a current verified directory',async()=>{
 const calls=[];
 const result=await discover([a],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  calls.push(host);return reply([a,c]);
 });assert.equal(result.selected,a);assert.deepEqual(result.origins,[a,c]);assert.deepEqual(calls,[b,a]);
});
test('replenishes slots with discovered hosts, excludes retired hosts and stops on a qualifying reply',async()=>{
 let clock=0;const calls=[],aborted=[];let releaseA;
 const selection=discover([a,b],'company',null,(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));
  if(host===a)return new Promise(resolve=>{releaseA=resolve;});
  if(host===b)return new Promise(()=>{});
  clock+=120;return Promise.resolve(reply([a,c]));
 },()=>clock);
 await tick();clock=350;releaseA(reply([a,c]));
 const result=await selection;
 assert.equal(result.selected,c);assert.deepEqual(calls,[a,b,c]);assert.deepEqual(aborted,[b]);
});
test('uses the fastest verified fallback when every completed reply exceeds 300ms',async()=>{
 let clock=0;const pending=new Map();
 const selection=discover([a,b],'company',null,host=>new Promise(resolve=>pending.set(host,resolve)),()=>clock);
 await tick();clock=350;pending.get(b)(reply([a,b]));await tick();
 clock=500;pending.get(a)(reply([a,b]));
 const result=await selection;assert.equal(result.selected,b);
});
test('never exceeds three active probes and aborts expired native requests before replacing slots',async()=>{
 const hosts=Array.from({length:7},(_,i)=>`https://probe${i}.example.com`);
 let active=0,max=0,aborted=0;
 const result=await discover(hosts,'company',null,(host,onAbort)=>{
  active++;max=Math.max(max,active);
  if(host!==hosts[6]){onAbort(()=>{active--;aborted++;});return new Promise(()=>{});}
  active--;return Promise.resolve(reply(hosts));
 },Date.now,{probeMs:15,totalMs:200});
 assert.equal(result.selected,hosts[6]);assert.equal(max,3);assert.equal(active,0);assert.equal(aborted,6);
});
test('a qualifying response does not wait for a hung first candidate',async()=>{
 const aborted=[];
 const result=await discover([a,b,c],'company',null,(host,onAbort)=>{
  onAbort(()=>aborted.push(host));
  return host===b?Promise.resolve(reply([a,b,c])):new Promise(()=>{});
 },Date.now,{probeMs:1000,totalMs:4000});
 assert.equal(result.selected,b);assert.deepEqual(aborted.sort(),[a,c]);
});
test('an earlier fast response from another company cannot win the race',async()=>{
 const aborted=[];
 const result=await discover([a,b,c],'company',{tenantId:'tenant'},(host,onAbort)=>{
  onAbort(()=>aborted.push(host));
  if(host===a)return Promise.resolve(reply([a,b,c],'other'));
  if(host===b)return new Promise(resolve=>setTimeout(()=>resolve(reply([a,b,c])),5));
  return new Promise(()=>{});
 });
 assert.equal(result.selected,b);assert.deepEqual(aborted,[c]);
});
test('the total budget selects a slower verified fallback and aborts hung requests',async()=>{
 const aborted=[];
 const result=await discover([a,b],'company',null,(host,onAbort)=>{
  onAbort(()=>aborted.push(host));
  return host===a?new Promise(resolve=>setTimeout(()=>resolve(reply([a,b])),5)):new Promise(()=>{});
 },Date.now,{probeMs:100,totalMs:40,acceptableMs:1});
 assert.equal(result.selected,a);assert.deepEqual(aborted,[b]);
});
test('rejects a different tenant, wrong company and malformed directories',async()=>{
 for(const data of [reply([a],'other'),reply([a],'tenant','other'),reply([b]),{}])
  await assert.rejects(discover([a],'company',{tenantId:'tenant'},async()=>data));
});
test('all-offline fails visibly instead of loading an unverified cached host',async()=>{
 await assert.rejects(discover([a],'company',{tenantId:'tenant',origins:[b],selected:b},async()=>{throw Error('offline')}),/连接服务器/);
});
test('all hung callbacks release discovery so the UI can offer retry',async()=>{
 await assert.rejects(discover([a,b],'company',null,()=>new Promise(()=>{}),
 Date.now,{probeMs:20,totalMs:100}),/连接服务器/);
});
test('a large cached directory has one total deadline and at most three in-flight requests',async()=>{
 const origins=Array.from({length:100},(_,i)=>`https://host${i}.example.com`);const calls=[];
 await assert.rejects(discover([a],'company',{origins},host=>{
  calls.push(host);return new Promise(()=>{});
 },Date.now,{probeMs:100,totalMs:20}),/连接服务器/);
 assert.equal(calls.length,3);
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
 const requests = [], windows = [], ticks = [], timers = new Map(), loginEvents = []; let sequence = 0;
 let finishDiscovery, restoredIdentity, foregroundHook;
 let parentHeight = 828;
 const flushLayout = () => { while (ticks.length) ticks.shift()(); };
 const context = {
  beginTiming: () => () => {}, timingSnapshot: () => [],
  nextTick: callback => { ticks.push(callback); },
  discover: () => new Promise(resolve => { finishDiscovery = resolve; }),
  safeAddress, debugScript, debugMessages, readinessScript, ref: value => ({value}), config: JSON.parse(readFileSync('mobile/webview-shell/src/config.json', 'utf8')),
  pageOrigin: url => { try { return new URL(url).origin; } catch { return null; } },
  sessionSignalScript: () => 'session-status-listener',
  posterBridgeScript: () => 'poster-save-listener', posterReceiver: () => ({receive() {}, dispose() {}}), savePoster() {},
  sharedSession: () => ({ configure() {}, async restore(url) {loginEvents.push(['restore',url]);return restoredIdentity;}, capture(url) {loginEvents.push(['capture',url]);return true;}, clear() {loginEvents.push(['clear']);} }),
  uni: {createSelectorQuery: () => { let callback; const query = {select:()=>query,boundingClientRect:fn=>{callback=fn;return query;},exec:()=>callback({height:parentHeight})};return query;},getStorageSync: () => ({tenantId:'tenant'}), request: request => requests.push(request),getSystemInfoSync: () => ({statusBarHeight: 24, windowHeight: 0}),onWindowResize: () => {},offWindowResize: () => {}},
  plus: {runtime:{appid:'test'},os:{name:'iOS'},webview: {create: (url,id,styles) => {
   const view = {events:{}, styles:[styles], url:'', scripts:[], closed:false,
    addEventListener(name,fn){this.events[name]=fn;},getURL(){return this.url;},setStyle(s){this.styles.push(s);},
    evalJS(s){this.scripts.push(s);},loadURL(url){this.requestedURL=url;},close(){this.closed=true;},
    show(){assert.fail('no independent show');},hide(){assert.fail('no independent hide');}};
   windows.push(view);return view;
  }}},
  getCurrentPages: () => [{$getAppWebview: () => ({append: () => {}})}],
  onReady: () => {}, onShow: fn => { foregroundHook = fn; }, onHide: () => {}, onUnload: () => {}, onBackPress: () => {},
  setTimeout: (fn,delay) => {const id=++sequence;timers.set(id,{fn,delay});return id;},
  clearTimeout: id => timers.delete(id),
 };
 const js = ts.transpileModule(script + '\nglobalThis.pageTest = {open, retry, state, active, token:()=>readinessToken,sessionToken:()=>sessionPrefix,resizeContent,refreshDebug,debugEnabled,debugRows};', {compilerOptions: {target: ts.ScriptTarget.ES2022}}).outputText;
 runInNewContext(js, context);
 const page = context.pageTest;page.open(a);page.active.value=a;
 const view=windows[0];
 assert.equal(view.requestedURL,a+'/?app_webview=1#/pages/login/index');
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
 const retry=windows[1];assert.equal(retry.requestedURL,b+'/?app_webview=1#/pages/login/index');
 assert.deepEqual(loginEvents,[['restore',b]], 'credential is restored before loading H5');
 retry.url=b+'/';retry.events.loaded();
 const signal=page.sessionToken();
 retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'other',sequence:1})});
 assert.equal(loginEvents.length,1,'other company cannot capture a credential');
 retry.url=c+'/';retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:1})});
 assert.equal(loginEvents.length,1,'unverified navigation cannot access credentials');
 retry.url=b+'/';retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:1})});
 assert.deepEqual(loginEvents.at(-1),['capture',b]);
 view.events.titleUpdate({title:signal+JSON.stringify({signedIn:false,tenantId:'tenant',sequence:2})});
 assert.deepEqual(loginEvents.at(-1),['capture',b],'old child cannot clear current login');
 retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:false,signedOut:true,tenantId:'tenant',sequence:2})});
 assert.deepEqual(loginEvents.at(-1),['clear']);
 retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:1})});
 assert.deepEqual(loginEvents.at(-1),['clear'], 'restored titles cannot replay an older sign-in after logout');
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
 assert.equal(page.state.value,'loading', 'rendering cannot pass the identity barrier');
 current.events.titleUpdate({title:page.sessionToken()+JSON.stringify({signedIn:false,tenantId:'tenant',sequence:1})});
 current.events.titleUpdate({title:page.token()});flushLayout();
 assert.equal(page.state.value,'ready');
 assert.equal(current.styles.at(-1).height,'804px');
 assert.equal(current.styles.some(style=>style.height==='504px'),false, 'retry cannot repeat startup pulse');
 pulse.fn();flushLayout();
 assert.equal(current.styles.at(-1).opacity,1, 'stale pulse callback has no effect');
 const clears=loginEvents.filter(event=>event[0]==='clear').length;
 current.events.titleUpdate({title:page.sessionToken()+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:2})});
 current.events.titleUpdate({title:page.sessionToken()+JSON.stringify({signedIn:false,tenantId:'tenant',sequence:3})});
 assert.equal(page.state.value,'error', 'temporary anonymous signal cannot reveal a login page');
 assert.equal(loginEvents.filter(event=>event[0]==='clear').length,clears, 'only explicit logout clears persistent credentials');

 // Authenticated cold starts must never mount the login route, including restricted accounts.
 restoredIdentity={tenantId:'tenant',signedIn:true,restricted:false};
 let auto=page.retry();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/assets/index');
 assert.equal(windows.at(-1).styles.at(-1).opacity,0,'home is still hidden until its own bootstrap confirms identity');
 restoredIdentity={tenantId:'tenant',signedIn:true,restricted:true};
 auto=page.retry();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/screen/index?path=%2Faccount%2Frestricted');
 restoredIdentity={tenantId:'tenant',signedIn:false,restricted:false};
 auto=page.retry();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/login/index');
 const mounted=windows.at(-1),count=windows.length;
 foregroundHook();foregroundHook();
 assert.equal(windows.length,count,'returning to foreground keeps the existing WebView');
 assert.equal(mounted.closed,false,'foreground refresh never closes the active page');
 assert.equal(page.active.value,b,'foreground return retains the active origin');

});
