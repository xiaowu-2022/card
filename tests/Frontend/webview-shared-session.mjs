import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const compile = path => ts.transpileModule(readFileSync(path, 'utf8'), {compilerOptions:{module:ts.ModuleKind.CommonJS,target:ts.ScriptTarget.ES2022}}).outputText;
const directory = {}, exports = {}, signals = {};
runInNewContext(compile('mobile/webview-shell/src/lib/directory.ts'), {exports:directory,setTimeout,clearTimeout});
runInNewContext(compile('mobile/webview-shell/src/lib/shared-session.ts'), {exports,require:()=>directory,setTimeout});
runInNewContext(compile('mobile/webview-shell/src/lib/session-signal.ts'), {exports:signals});
const a='https://a.example.test', b='https://b.example.test', c='https://foreign.example.test';
const cookie='eyJ'+'A'.repeat(150)+'%3D';
const listing={tenantId:'tenant-a',origins:[a,b],selected:b,fetchedAt:Date.now()};
function setup(nativeVault=true) {
    let saved=null, meta=null;
    const jar=new Map(), writes=[];
    const ports={
        cookies:{get:url=>jar.get(url)??'',set:(url,value)=>{writes.push([url,value]);jar.set(url,value.includes('Max-Age=0')?'':value.split(';')[0]);}},
        vault:nativeVault?{read:()=>saved,write:value=>{saved=value;}}:null,
        metadata:{get:()=>meta,set:value=>{meta=value;}},
    };
    return {ports,jar,writes,create:()=>exports.sharedSession(ports),saved:()=>saved,meta:()=>meta};
}
test('only an unambiguous encrypted remember cookie is eligible, never session/admin cookies',()=>{
    assert.equal(exports.readRememberCookie(`admin=secret; consumer_remember=${cookie}; laravel_session=other`),cookie);
    for(const header of ['admin=secret','consumer_remember=short',`consumer_remember=${cookie}; consumer_remember=${cookie}`,`consumer_remember=${cookie}\r\nX=bad`])
        assert.equal(exports.readRememberCookie(header),null);
    assert.equal(exports.pageOrigin(b+'/path?x=1'),b);
    assert.equal(exports.pageOrigin('http://b.example.test'),null);
});
test('adopts existing native cookie once and restores it only to verified same-company hosts',async()=>{
    const h=setup();h.jar.set(a+'/',`admin=other; consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);assert.equal(h.saved(),cookie);
    await s.restore(b);
    assert.equal(exports.readRememberCookie(h.jar.get(b+'/')),cookie);
    assert.ok(h.writes.every(([url,value])=>url===b+'/' && value.startsWith('consumer_remember=') && /Secure; HttpOnly; SameSite=Lax/.test(value) && !value.includes('Domain=')));
    await assert.rejects(s.restore(c),/Unverified/);assert.equal(s.capture(c),false);
    assert.throws(()=>s.configure({...listing,tenantId:'other'}),/Company/);
    const restarted=h.create();restarted.configure(listing);await restarted.restore(a);
    assert.equal(exports.readRememberCookie(h.jar.get(a+'/')),cookie);
});
test('logout tombstone prevents resurrection even if an old cookie reappears',async()=>{
    const h=setup();h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);s.clear();
    assert.equal(h.saved(),null);assert.equal(h.meta().signedIn,false);
    h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const restarted=h.create();restarted.configure(listing,a);await restarted.restore(b);
    assert.equal(h.jar.get(b+'/'),'');assert.equal(h.saved(),null);
});
test('iOS uses native HttpOnly cookie store and nonsecret source metadata across restarts',async()=>{
    const h=setup(false);h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);await s.restore(b);s.capture(b);
    assert.ok(!JSON.stringify(h.meta()).includes(cookie));
    const restarted=h.create();restarted.configure({...listing,selected:a});await restarted.restore(a);
    assert.equal(exports.readRememberCookie(h.jar.get(a+'/')),cookie);
    restarted.clear();h.jar.set(b+'/',`consumer_remember=${cookie}`);
    const loggedOut=h.create();loggedOut.configure(listing,b);await loggedOut.restore(a);
    assert.equal(h.jar.get(a+'/'),'');
});
test('missing Android key does not fall back to a copied native cookie; storage errors do not persist plaintext',async()=>{
    const h=setup();h.ports.metadata.set({initialized:true,signedIn:true,source:a});
    h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);await s.restore(b);assert.equal(h.jar.get(b+'/'),'');
    h.ports.vault.write=()=>{throw Error('unavailable');};
    assert.throws(()=>s.capture(a),/unavailable/);
    assert.equal(h.saved(),null);
});
test('the native cookie commit must complete before the destination opens',async()=>{
    const h=setup();h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);
    h.ports.cookies.set=(url,value)=>setTimeout(()=>h.jar.set(url,value.split(';')[0]),75);
    await s.restore(b);assert.equal(exports.readRememberCookie(h.jar.get(b+'/')),cookie);
});
test('a failed capture after account change cannot restore the earlier account',async()=>{
    const h=setup();h.jar.set(a+'/',`consumer_remember=${cookie}`);
    const s=h.create();s.configure(listing,a);
    h.jar.set(a+'/',`consumer_remember=${'B'.repeat(160)}`);
    const original=h.ports.vault.write;
    h.ports.vault.write=value=>{if(value)throw Error('keystore failed');original(null);};
    assert.throws(()=>s.capture(a),/failed/);
    await s.restore(b);assert.equal(h.jar.get(b+'/'),'');assert.equal(h.meta().signedIn,false);
});
test('session signal transports only status and company, including a completed bootstrap before injection',()=>{
    const titles=[], events={};let title='App';
    const window={__consumerSessionState:{signedIn:true,tenantId:'tenant-a',token:'must-not-cross'},
        addEventListener:(name,fn)=>events[name]=fn,removeEventListener:()=>{},setTimeout:()=>{}};
    const document={get title(){return title;},set title(value){title=value;titles.push(value);}};
    runInNewContext(signals.sessionSignalScript('signal:'),{window,document});
    assert.deepEqual(JSON.parse(titles[0].slice(7)),{signedIn:true,tenantId:'tenant-a',sequence:1});
    events['consumer-session-state']({detail:{signedIn:false,tenantId:'tenant-a',password:'never'}});
    assert.ok(titles.every(value=>!value.includes('token')&&!value.includes('password')));
    assert.equal(JSON.parse(titles.at(-1).slice(7)).signedIn,false);
    assert.equal(JSON.parse(titles.at(-1).slice(7)).sequence,2);
});
