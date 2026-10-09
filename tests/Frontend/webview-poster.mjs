import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const compile = source => ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText;
const host = {};
runInNewContext(compile(readFileSync('mobile/webview-shell/src/lib/poster-bridge.ts','utf8')), { exports: host, setTimeout, clearTimeout });
const png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=';
const tick = () => new Promise(resolve => setTimeout(resolve, 10));

function channel(save = async () => {}, drop = () => false) {
    let trusted = true, title = '邀请海报'; const sent = [];
    const document = {};
    const context = { document, window: {}, setTimeout, clearTimeout };
    const receiver = host.posterReceiver({ prefix: 'specpay-poster-test:', trusted: () => trusted,
        evaluate: script => { if (!drop('reply', script)) runInNewContext(script, context); }, save });
    Object.defineProperty(document, 'title', { get: () => title, set: value => { title = value; sent.push(value); if (!drop('title', value)) void receiver.receive(value); } });
    runInNewContext(host.posterBridgeScript('specpay-poster-test:'), context);
    return { receiver, context, sent, setTrusted: value => trusted = value, bridge: context.window.__specpayPoster };
}

test('acknowledged chunks save exactly one bounded PNG, report success only after the gallery callback', async () => {
    let finish; const saves = [];
    const c = channel(data => { saves.push(data); return new Promise(resolve => finish = resolve); });
    const large = 'data:image/png;base64,' + Buffer.concat([Buffer.from(png.split(',')[1], 'base64'), Buffer.alloc(40000)]).toString('base64');
    let complete = false;
    const task = c.bridge.save(large).then(() => complete = true);
    for (let i=0; !finish && i<100; i++) await tick();
    assert.equal(saves.length, 1); assert.equal(saves[0], large); assert.equal(complete, false);
    assert.ok(c.sent.length > 2); assert.ok(c.sent.every(s => s.length < 4096));
    const replay = c.sent.at(-1); await c.receiver.receive(replay); assert.equal(saves.length, 1);
    finish(); await task;
    assert.equal(c.context.document.title, '邀请海报');
    await c.receiver.receive(replay); assert.equal(saves.length, 1);
    c.receiver.dispose();
});

test('denied gallery save rejects and untrusted or stale windows cannot request a save', async () => {
    const c = channel(async () => { throw Error('permission secret'); });
    await assert.rejects(c.bridge.save(png), /failed/);
    let count = 0;
    const isolated = channel(async () => count++);
    isolated.setTrusted(false);
    await isolated.receiver.receive('specpay-poster-test:'+JSON.stringify({id:1,index:0,total:1,chunk:png}));
    isolated.setTrusted(true); isolated.receiver.dispose();
    await isolated.receiver.receive('specpay-poster-test:'+JSON.stringify({id:2,index:0,total:1,chunk:png}));
    assert.equal(count,0); c.receiver.dispose();
});

test('non-PNG, remote URLs, excessive dimensions and out-of-order messages never reach the gallery', async () => {
    assert.equal(host.validPoster('https://private.example/image.png'),false);
    assert.equal(host.validPoster(png),true);
    const huge = Buffer.from(png.split(',')[1], 'base64'); huge.writeUInt32BE(20000,16);
    assert.equal(host.validPoster('data:image/png;base64,'+huge.toString('base64')),false);
    const c = channel(() => assert.fail('must not save'));
    await assert.rejects(c.bridge.save('file:///private/image.png'), /invalid/);
    await c.receiver.receive('specpay-poster-test:'+JSON.stringify({id:1,index:1,total:2,chunk:png}));
    await c.receiver.receive('specpay-poster-test:'+JSON.stringify({id:2,index:0,total:1,chunk:'data:image/png;base64,'+huge.toString('base64')}));
    c.receiver.dispose();
});

test('native adapter uses only a generated local PNG path, cleans up and waits for actual gallery result', async () => {
    const events = []; let galleryOk;
    const runtime = {
        nativeObj: { Bitmap: class {
            loadBase64Data(data, ok) { assert.equal(data,png); events.push('decode'); ok(); }
            save(path, opts, ok) { assert.match(path,/^_doc\/invitation-[a-z0-9-]+\.png$/); events.push('file'); ok(); }
            clear() { events.push('clear'); }
        } },
        gallery: { save(path,ok) {events.push('gallery'); galleryOk=ok;} },
        io: { resolveLocalFileSystemURL(path, ok) {ok({remove(ok){events.push('remove');ok();}});} },
    };
    let completed = false;
    const task = host.savePoster(runtime,png,()=>true).then(()=>completed=true);
    await tick(); assert.equal(completed,false); assert.deepEqual(events,['decode','file','gallery']);
    await assert.rejects(host.savePoster(runtime,png,()=>true));
    galleryOk();await task;assert.deepEqual(events,['decode','file','gallery','clear','remove']);
    let active = true;
    runtime.nativeObj.Bitmap.prototype.save = (path,opts,ok) => {active=false;ok();};
    runtime.gallery.save = () => assert.fail('navigation invalidated the save');
    await assert.rejects(host.savePoster(runtime,png,()=>active), /stale/);
});

test('H5 uses the native bridge, old WebViews show update-required, ordinary browsers keep downloads', async () => {
    const exports={}, downloads=[], window={}; const location={search:'?app_webview=1'};
    const navigator={userAgent:'ordinary-browser'};
    const document={body:{appendChild(){}},createElement:()=>({click(){downloads.push(this.download);},remove(){}})};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/lib/poster-save.ts','utf8')), {exports,window,location,document,navigator,URLSearchParams,setTimeout,clearTimeout});
    await assert.rejects(exports.saveBrowserPoster(png,'123456'),/update-required/);
    assert.equal(downloads.length,0);
    location.search='';navigator.userAgent='Html5Plus uni-app';
    await assert.rejects(exports.saveBrowserPoster(png,'123456'),/update-required/);
    navigator.userAgent='ordinary-browser';location.search='?app_webview=1';
    let saved; window.__specpayPoster={version:1,save:async data=>saved=data};
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'saved');assert.equal(saved,png);
    delete window.__specpayPoster;location.search='';
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'download');assert.deepEqual(downloads,['invitation-123456.png']);
});


test('lost title events and intermediate/final acknowledgements recover without duplicate saves', async () => {
    let droppedTitle = false, droppedNext = false, droppedSaved = false, count = 0;
    const c = channel(async () => { count++; }, (kind, value) => {
        if (kind === 'title' && value.startsWith('specpay-poster-') && !droppedTitle) { droppedTitle = true; return true; }
        if (kind === 'reply' && value.includes('"next"') && !droppedNext) { droppedNext = true; return true; }
        if (kind === 'reply' && value.includes('"saved"') && !droppedSaved) { droppedSaved = true; return true; }
        return false;
    });
    const large = 'data:image/png;base64,' + Buffer.concat([Buffer.from(png.split(',')[1], 'base64'), Buffer.alloc(6000)]).toString('base64');
    await c.bridge.save(large);
    assert.equal(count, 1);
    assert.ok(droppedTitle && droppedNext && droppedSaved);
    assert.equal(c.context.document.title, '邀请海报');
    c.receiver.dispose();
});

test('retries during an open permission dialog wait and never start a second gallery save', async () => {
    let finish, count = 0;
    const c = channel(() => { count++; return new Promise(resolve => finish = resolve); });
    let done = false;
    const task = c.bridge.save(png).then(() => done = true);
    await new Promise(resolve => setTimeout(resolve, 1100));
    assert.equal(count, 1); assert.equal(done, false);
    finish(); await task;
    c.receiver.dispose();
});

test('H5 watchdog releases a non-responsive old bridge and cancels it', async () => {
    const exports = {};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/lib/poster-save.ts','utf8')), {exports,setTimeout,clearTimeout});
    let cancelled = 0;
    await assert.rejects(exports.boundedPosterSave(() => new Promise(() => {}), () => cancelled++, 10), /timeout/);
    assert.equal(cancelled, 1);
    await exports.boundedPosterSave(async () => {}, () => cancelled++, 10);
    await tick(); assert.equal(cancelled, 1);
});


test('a new explicit attempt replaces an abandoned transfer but cannot replay its late chunks', async () => {
    let count = 0;
    const receiver = host.posterReceiver({prefix:'p:',trusted:()=>true,evaluate:()=>{},save:async()=>count++});
    const send = (id,index,total,chunk) => receiver.receive('p:'+JSON.stringify({id,index,total,chunk}));
    await send(1,0,2,png.slice(0,40));
    await send(2,0,1,png);
    await send(1,1,2,png.slice(40));
    assert.equal(count,1);
    receiver.dispose();
});

test('a completely silent native channel times out and releases its timers and title', async () => {
    let now = 0; const jobs = new Map(); let next = 0;
    const document = {title:'邀请海报'}, window = {};
    runInNewContext(host.posterBridgeScript('p:'), {window,document,Date:{now:()=>now},
        setTimeout:(fn,delay)=>{ const id=++next; jobs.set(id,{fn,due:now+delay});return id; },
        clearTimeout:id=>jobs.delete(id)});
    const pending = assert.rejects(window.__specpayPoster.save(png), /timeout/);
    for(let i=0;i<30 && jobs.size;i++) {
        const [id,job] = [...jobs].sort((a,b)=>a[1].due-b[1].due)[0];
        jobs.delete(id);now=job.due;job.fn();
    }
    await pending;
    assert.ok(now <= 10500);assert.equal(jobs.size,0);assert.equal(document.title,'邀请海报');
});


test('iPhone and iPad use manual save without file sharing and never claim a download', async () => {
    const exports = {}, downloads = [], window = {};
    const location = {search:'?app_webview=1'};
    const navigator = {userAgent:'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X)',maxTouchPoints:5};
    const document = {body:{appendChild(){}},createElement:()=>({click(){downloads.push(this.download);},remove(){}})};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/lib/poster-save.ts','utf8')),
        {exports,window,location,document,navigator,URLSearchParams,setTimeout,clearTimeout});
    window.__specpayPoster = {version:1,save:()=>assert.fail('iOS must not wait on the shell bridge')};
    assert.equal(exports.isIOSWebview(),true);
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
    delete window.__specpayPoster;
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
    navigator.userAgent='Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15)';
    assert.equal(exports.isIOSWebview(),true);
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
    navigator.userAgent='Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X) Html5Plus';location.search='';
    assert.equal(exports.isIOSWebview(),true);
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
    assert.equal(downloads.length,0);
    navigator.userAgent='Android Html5Plus';
    assert.equal(exports.isIOSWebview(),false);
    await assert.rejects(exports.saveBrowserPoster(png,'123456'),/update-required/);
});


test('iOS shares a PNG file in the user gesture and distinguishes cancellation and unavailable sharing', async () => {
    const exports = {}, calls = [];
    const navigator = {userAgent:'iPhone', canShare: ({files}) => files[0].type === 'image/png',
        share: ({files}) => { calls.push(files[0]); return Promise.resolve(); }};
    runInNewContext(compile(readFileSync('mobile/uni-app/src/lib/poster-save.ts','utf8')),
        {exports, navigator, File, atob, Uint8Array, setTimeout, clearTimeout});
    const pending = exports.saveBrowserPoster(png,'123456');
    assert.equal(calls.length,1,'share invoked before yielding the user gesture');
    assert.equal(await pending,'shared');
    assert.equal(calls[0].name,'invitation-123456.png');
    assert.equal(Buffer.from(await calls[0].arrayBuffer()).toString('base64'),png.split(',')[1]);
    navigator.share = () => Promise.reject({name:'AbortError'});
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'cancelled');
    navigator.share = () => Promise.reject({name:'NotAllowedError'});
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
    navigator.canShare = () => false;
    navigator.share = () => assert.fail('unsupported file sharing must not be called');
    assert.equal(await exports.saveBrowserPoster(png,'123456'),'manual');
});
