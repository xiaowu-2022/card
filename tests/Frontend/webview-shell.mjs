import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const js = ts.transpileModule(readFileSync('mobile/webview-shell/src/lib/directory.ts','utf8'),{compilerOptions:{module:ts.ModuleKind.ES2022,target:ts.ScriptTarget.ES2022}}).outputText;
const {discover:discoverImpl,origin}=await import('data:text/javascript;base64,'+Buffer.from(js).toString('base64'));
const defaults={probeMs:2000,totalMs:4000};
const discover=(seeds,slug,cached,probe,now=Date.now,limits=defaults,random=()=>0)=>discoverImpl(seeds,slug,cached,probe,now,limits,random);
const a='https://a.example.com',b='https://b.example.com',c='https://c.example.com';
const hosts=Array.from({length:23},(_,i)=>`https://probe${i}.example.com`);
const reply=(origins,id='tenant',slug='company')=>({tenant:{id,slug},origins});
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('validates HTTPS candidates and excludes the static distribution host',()=>{
 for(const bad of ['http://a.example.com','https://a.example.com/x','https://u:p@a.example.com','javascript:alert(1)','https://zb33333.com'])assert.equal(origin(bad),null);
 assert.equal(origin(a),a);
});
test('the first verified response wins even after the former speed threshold and aborts other probes',async()=>{
 let clock=0;const calls=[],aborted=[],pending=new Map();
 const selection=discover(hosts,'company',null,(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));return new Promise(resolve=>pending.set(host,resolve));
 },()=>clock);
 await tick();assert.deepEqual(calls,hosts.slice(0,3));
 clock=1500;pending.get(hosts[1])(reply(hosts));
 const result=await selection;
 assert.equal(result.selected,hosts[1]);assert.deepEqual(result.origins,hosts);
 assert.deepEqual(aborted,[hosts[0],hosts[2]]);assert.equal(calls.length,3);
 pending.get(hosts[0])(reply(hosts));await tick();assert.equal(result.selected,hosts[1]);
});
test('cached selections are ordinary randomized candidates, never forced into the first group',async()=>{
 const calls=[];
 const result=await discover(hosts.slice(0,10),'company',{tenantId:'tenant',selected:hosts[22],origins:hosts},async host=>{
  calls.push(host);return reply(hosts);
 });
 assert.equal(result.selected,hosts[0]);assert.deepEqual(calls,hosts.slice(0,3));assert.ok(!calls.includes(hosts[22]));
 const randomCalls=[];
 const randomized=await discover(hosts,'company',null,async host=>{randomCalls.push(host);return reply(hosts);},Date.now,defaults,()=>0.999);
 assert.equal(randomized.selected,hosts.at(-1));assert.equal(randomCalls.length,3);
});
test('an unlisted cached selection cannot enter the candidate pool',async()=>{
 const calls=[];
 await discover([a],'company',{selected:b,origins:[a]},async host=>{calls.push(host);return reply([a]);});
 assert.deepEqual(calls,[a]);
});
test('the second group waits for every first-group failure without refilling individual slots',async()=>{
 const calls=[],pending=new Map(),aborted=[];
 const run=discover(hosts,'company',{tenantId:'tenant'},(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));return new Promise((resolve,reject)=>pending.set(host,{resolve,reject}));
 });
 await tick();pending.get(hosts[0]).reject(Error('offline'));await tick();assert.equal(calls.length,3);
 pending.get(hosts[1]).resolve(reply(hosts,'foreign'));await tick();assert.equal(calls.length,3);
 pending.get(hosts[2]).resolve(reply([a]));await tick();assert.deepEqual(calls,hosts.slice(0,6));
 pending.get(hosts[4]).resolve(reply(hosts));
 assert.equal((await run).selected,hosts[4]);assert.deepEqual(aborted,[hosts[3],hosts[5]]);
});
test('first-group timeouts abort before the second group starts, and late results cannot win',async()=>{
 const calls=[],aborted=[],pending=new Map();let active=0,max=0;
 const run=discover(hosts,'company',null,(host,onAbort)=>{
  calls.push(host);active++;max=Math.max(max,active);
  onAbort(()=>{active--;aborted.push(host);});
  if(host===hosts[4]){active--;return Promise.resolve(reply(hosts));}
  return new Promise(resolve=>pending.set(host,resolve));
 },Date.now,{probeMs:15,totalMs:100});
 const result=await run;
 assert.equal(result.selected,hosts[4]);assert.equal(max,3);assert.equal(active,0);
 assert.deepEqual(calls,hosts.slice(0,6));assert.deepEqual(aborted,[...hosts.slice(0,3),hosts[3],hosts[5]]);
 pending.get(hosts[0])(reply(hosts));await tick();assert.equal(result.selected,hosts[4]);
});
test('a response beyond its group deadline is rejected even if the native timer callback is delayed',async()=>{
 let clock=0;const pending=new Map(),calls=[],aborted=[];
 const run=discover(hosts,'company',null,(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));return new Promise(resolve=>pending.set(host,resolve));
 },()=>clock);
 await tick();clock=2100;pending.get(hosts[0])(reply(hosts));await tick();
 assert.deepEqual(calls,hosts.slice(0,6));assert.deepEqual(aborted,hosts.slice(1,3));
 clock=2200;pending.get(hosts[4])(reply(hosts));
 assert.equal((await run).selected,hosts[4]);
});
test('immediate failures stop after six candidates and a retry can draw a different group',async()=>{
 const calls=[];
 await assert.rejects(discover(hosts,'company',null,async host=>{calls.push(host);throw Error('offline');}),/连接服务器/);
 assert.deepEqual(calls,hosts.slice(0,6));assert.equal(new Set(calls).size,6);
 const retry=[];
 await discover(hosts,'company',null,async host=>{retry.push(host);return reply(hosts);},Date.now,defaults,()=>0.999);
 assert.equal(retry[0],hosts.at(-1));assert.equal(retry.length,3);
});
test('the default deadline aborts all six hung probes within four seconds',async()=>{
 const calls=[],aborted=[];const start=Date.now();
 await assert.rejects(discover(hosts,'company',null,(host,onAbort)=>{
  calls.push({host,elapsed:Date.now()-start});onAbort(()=>aborted.push(host));return new Promise(()=>{});
 }),/连接服务器/);
 assert.equal(calls.length,6);assert.equal(aborted.length,6);
 assert.ok(calls[3].elapsed>=1900 && calls[3].elapsed<3000);
 assert.ok(Date.now()-start>=3900 && Date.now()-start<5000);
});
test('an independent total deadline stops the first group without starting the second',async()=>{
 const calls=[],aborted=[];
 await assert.rejects(discover(hosts,'company',null,(host,onAbort)=>{
  calls.push(host);onAbort(()=>aborted.push(host));return new Promise(()=>{});
 },Date.now,{probeMs:100,totalMs:15}),/连接服务器/);
 assert.equal(calls.length,3);assert.deepEqual(aborted,hosts.slice(0,3));
});
test('short, empty and duplicate candidate pools never repeat a host or send invalid candidates',async()=>{
 for(const seeds of [[],[a],[a,b],[a,b,c,hosts[0]]]) {
  const calls=[];
  await assert.rejects(discover([...seeds,...seeds,'http://bad.test','https://zb33333.com'],'company',{origins:seeds},async host=>{calls.push(host);throw Error('offline');}));
  assert.deepEqual(calls,seeds);
 }
});
test('fresh full directories replace stale caches without probing newly discovered hosts',async()=>{
 const calls=[];
 const result=await discover([a,b],'company',{tenantId:'tenant',selected:b,origins:[a,b]},async host=>{
  calls.push(host);return host===b?reply([b,c]):reply([b,c]);
 });
 assert.equal(result.selected,b);assert.deepEqual(result.origins,[b,c]);assert.deepEqual(calls,[a,b]);
});
test('large fresh directories are retained completely while only six candidates can be probed',async()=>{
 const directory=Array.from({length:150},(_,i)=>`https://directory${i}.example.com`),calls=[];
 const result=await discover([a],'company',null,async host=>{calls.push(host);return reply([a,...directory]);});
 assert.deepEqual(calls,[a]);assert.deepEqual(result.origins,[a,...directory]);
 const tail=await discover([], 'company', {tenantId:'tenant',origins:directory},async host=>reply(directory),Date.now,defaults,()=>0.999);
 assert.equal(tail.selected,directory.at(-1));
});
test('a faster response from another company cannot win; malformed or inactive membership fails closed',async()=>{
 for(const data of [reply([a],'foreign'),reply([a],'tenant','foreign'),reply([b]),{},null])
  await assert.rejects(discover([a],'company',{tenantId:'tenant'},async()=>data));
 const aborted=[];
 const result=await discover([a,b,c],'company',{tenantId:'tenant'},(host,onAbort)=>{
  onAbort(()=>aborted.push(host));
  if(host===a)return Promise.resolve(reply([a,b,c],'foreign'));
  if(host===b)return new Promise(resolve=>setTimeout(()=>resolve(reply([a,b,c])),5));
  return new Promise(()=>{});
 });
 assert.equal(result.selected,b);assert.deepEqual(aborted,[c]);
});
test('synchronous probe failures and throwing abort handlers cannot hang selection',async()=>{
 const calls=[];
 await assert.rejects(discover(hosts,'company',null,host=>{calls.push(host);throw Error('offline');}));
 assert.equal(calls.length,6);
 const result=await discover([a,b,c],'company',null,(host,onAbort)=>{
  onAbort(()=>{throw Error('abort');});return host===b?Promise.resolve(reply([a,b,c])):new Promise(()=>{});
 });assert.equal(result.selected,b);
});
test('built-in domains are sampled rather than traversed through their last entry',async()=>{
 const config=JSON.parse(readFileSync('mobile/webview-shell/src/config.json','utf8')),calls=[];
 const result=await discover(config.seeds,config.tenantSlug,null,async host=>{
  calls.push(host);return reply(config.seeds,'tenant',config.tenantSlug);
 },Date.now,defaults,()=>0.999);
 assert.equal(result.selected,config.seeds.at(-1));assert.equal(calls.length,3);
 assert.deepEqual(result.origins,config.seeds);
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
 let parentHeight = 828, clock = 100000;
 const flushLayout = () => { while (ticks.length) ticks.shift()(); };
 const context = {
  Date: class extends Date { static now() { return clock; } },
  beginTiming: () => () => {}, timingSnapshot: () => [],
  nextTick: callback => { ticks.push(callback); },
  discover: () => new Promise(resolve => { finishDiscovery = resolve; }),
  safeAddress, debugScript, debugMessages, readinessScript, ref: value => ({value}), config: JSON.parse(readFileSync('mobile/webview-shell/src/config.json', 'utf8')),
  pageOrigin: url => { try { return new URL(url).origin; } catch { return null; } },
  sessionSignalScript: () => 'session-status-listener',
  posterBridgeScript: () => 'poster-save-listener', posterReceiver: () => ({receive() {}, dispose() {}}), savePoster() {},
  iosCredentialVault: () => ({}), validToken: () => true,
  tokenSession: () => ({ script: () => 'token-injection', configure() {}, async restore(url) {loginEvents.push(['restore',url]);return restoredIdentity;}, capture(url) {loginEvents.push(['capture',url]);return true;}, clear() {loginEvents.push(['clear']);} }),
  uni: {createSelectorQuery: () => { let callback; const query = {select:()=>query,boundingClientRect:fn=>{callback=fn;return query;},exec:()=>callback({height:parentHeight})};return query;},getStorageSync: () => ({tenantId:'tenant'}), request: request => requests.push(request),getSystemInfoSync: () => ({statusBarHeight: 24, windowHeight: 0}),onWindowResize: () => {},offWindowResize: () => {}},
  plus: {io:{convertLocalFileSystemURL: path => path},runtime:{appid:'test'},os:{name:'iOS'},webview: {create: (url,id,styles) => {
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
 const js = ts.transpileModule(script + '\nglobalThis.pageTest = {open, retry, back, state, active, token:()=>readinessToken,sessionToken:()=>sessionPrefix,resizeContent,refreshDebug,debugEnabled,debugRows};', {compilerOptions: {target: ts.ScriptTarget.ES2022}}).outputText;
 runInNewContext(js, context);
 const page = context.pageTest;page.open(a);page.active.value=a;
 const view=windows[0];
 assert.equal(view.requestedURL,a+'/?app_webview=1#/pages/login/index');
 assert.equal(view.styles[0].bottom,'0px');assert.equal(view.styles[0].height,undefined);
 assert.equal(view.styles[0].position,'absolute');
 assert.equal(view.styles[0].opacity,0);assert.equal(view.styles[0].render,'always');
 view.events.loaded();assert.equal(view.scripts.length,0);
 view.url=null;view.events.loaded();view.events.rendering();
 for (const event of [null,undefined,{}, {title:null}, {title:42}]) view.events.titleUpdate(event);
 assert.equal(page.state.value,'loading');assert.equal(view.styles.at(-1).opacity,0);
 view.url=a+'/';view.events.loaded();assert.equal(page.state.value,'loading');assert.equal(view.scripts.length,0);
 let completeBack;view.canBack=callback=>{completeBack=callback;};page.back();
 const oldToken=page.token();
 view.events.titleUpdate({title:'Spec Pay'});assert.equal(page.state.value,'loading');
 clock += 10000; view.events.loading();
 assert.ok([...timers.values()].some(t=>t.delay===20000), 'repeated loading does not reset the thirty-second budget');
 assert.equal([...timers.values()].some(t=>t.delay===30000),false);
 [...timers.values()].find(t=>t.delay===20000).fn();assert.equal(page.state.value,'error');assert.equal(view.closed,true);
 view.events.titleUpdate({title:oldToken});assert.equal(page.state.value,'error');
 const reconnect = page.retry();
 assert.equal(page.state.value,'discovering');assert.equal(windows.length,1);
 await Promise.resolve();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b,fetchedAt:Date.now()});
 await reconnect;
 const retry=windows[1];
 retry.back=()=>assert.fail('stale back callback must not navigate a replacement page');
 completeBack({canBack:true});completeBack({canBack:false});
 assert.equal(retry.requestedURL,b+'/?app_webview=1#/pages/login/index');
 assert.deepEqual(loginEvents,[['restore',b]], 'credential is restored before loading H5');
 retry.url=b+'/';
 [...timers.values()].find(t=>t.delay===350).fn();
 assert.ok(retry.scripts.includes('session-status-listener'), 'identity listener starts even if rendering and loaded never fire');
 assert.ok(retry.scripts.some(script=>script.includes('uni-page-body')), 'paint checks start without native render/load events');
 const listeners = retry.scripts.filter(script=>script==='session-status-listener').length;
 retry.events.rendering();
 assert.equal(retry.scripts.filter(script=>script==='session-status-listener').length,listeners + 1, 'retry listener injection until bootstrap acknowledges a possibly dropped early evalJS');
 const signal=page.sessionToken();
 retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'other',sequence:1})});
 assert.equal(loginEvents.length,1,'other company cannot capture a credential');
 retry.url=c+'/';retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:1})});
 assert.equal(loginEvents.length,1,'unverified navigation cannot access credentials');
 retry.url=b+'/';retry.events.titleUpdate({title:signal+JSON.stringify({signedIn:true,tenantId:'tenant',sequence:1})});
 assert.deepEqual(loginEvents.at(-1),['capture',b]);
 const acknowledged = retry.scripts.filter(script=>script==='session-status-listener').length;
 retry.events.rendering();
 assert.equal(retry.scripts.filter(script=>script==='session-status-listener').length,acknowledged,'acknowledged identity does not restart cookie capture');
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
 let auto=page.retry();await Promise.resolve();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/assets/index');
 assert.equal(windows.at(-1).styles.at(-1).opacity,0,'home is still hidden until its own bootstrap confirms identity');
 restoredIdentity={tenantId:'tenant',signedIn:true,restricted:true};
 auto=page.retry();await Promise.resolve();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/screen/index?path=%2Faccount%2Frestricted');
 restoredIdentity={tenantId:'tenant',signedIn:false,restricted:false};
 auto=page.retry();await Promise.resolve();finishDiscovery({tenantId:'tenant',origins:[a,b],selected:b});await auto;
 assert.equal(windows.at(-1).requestedURL,b+'/?app_webview=1#/pages/login/index');
 const mounted=windows.at(-1),count=windows.length;
 foregroundHook();foregroundHook();
 assert.equal(windows.length,count,'returning to foreground keeps the existing WebView');
 assert.equal(mounted.closed,false,'foreground refresh never closes the active page');
 assert.equal(page.active.value,b,'foreground return retains the active origin');

 clock+=61000;foregroundHook();
 const sharedDiscovery=finishDiscovery;
 foregroundHook();assert.equal(finishDiscovery,sharedDiscovery,'foreground refresh is deduplicated');
 assert.equal(mounted.closed,false,'background directory refresh retains the mounted page');
 const reconnecting=page.retry();await Promise.resolve();
 assert.equal(finishDiscovery,sharedDiscovery,'manual reconnect shares the pending foreground discovery');
 sharedDiscovery({tenantId:'tenant',origins:[a,b],selected:a});await reconnecting;
 assert.equal(page.active.value,a);

});

// A missing native callback must neither hang restoration nor accept a late identity.
test('session verification has an independent aborting deadline and ignores late success', async () => {
 const {runInNewContext}=await import('node:vm');
 const source=readFileSync('mobile/webview-shell/src/pages/index/index.vue','utf8');
 const expression=source.slice(source.indexOf('async function nativeApi'),source.indexOf('async function configureLogin'));
 let callback,request,aborted=0;const timers=new Set();
 const context={config:{tenantSlug:'company'},rememberCookie:'consumer_remember',LoginRestoreUnavailable:class extends Error{},
  uni:{request: options=>{request=options;return {abort(){aborted++;}};}},
  setTimeout: fn=>{callback=fn;timers.add(fn);return fn;},clearTimeout: fn=>timers.delete(fn)};
 const js=ts.transpileModule(expression+'\nglobalThis.verify = verifyToken;', {compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText;
 runInNewContext(js,context);
 const pending=context.verify(a,null);
 const rejected=assert.rejects(pending);
 callback();await rejected;assert.equal(aborted,1);assert.equal(timers.size,0);
 request.success({statusCode:200,data:{tenant:{id:'tenant',slug:'company'},user:null,restricted:false}});
 assert.equal(aborted,1);
 const successful=context.verify(a,null);
 request.success({statusCode:200,data:{tenant:{id:'tenant',slug:'company'},user:null,restricted:false}});
 assert.equal((await successful).signedIn,false);assert.equal(timers.size,0);
});
