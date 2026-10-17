import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const source=readFileSync('mobile/uni-app/src/pages/login/index.vue','utf8');
function harness() {
    const session={value:null},home=[],hooks={},requests=[];
    let pending;
    const context={ref:value=>({value}),session,native:false,changeLocale(){},
        bootstrap(){ requests.push('bootstrap');return new Promise((resolve,reject)=>{pending={resolve,reject};}); },
        onShow:fn=>hooks.show=fn,onHide:fn=>hooks.hide=fn,useSensitiveScreen(){},useFormFeedback:()=>({begin(){}}),
        explainError:()=>({network:'Unavailable'}),home:path=>home.push(path),login:async()=>{},
    };
    const script=source.split('<script setup lang="ts">')[1].split('</script>')[0].replace(/^import .*;\n/gm,'');
    const js=ts.transpileModule(script+'\nglobalThis.result={checkingSession,sessionCheckFailed,verifySession};',{compilerOptions:{target:ts.ScriptTarget.ES2022}}).outputText;
    runInNewContext(js,context);
    return {session,home,hooks,requests,result:context.result,pending:()=>pending};
}
const tick=()=>new Promise(resolve=>setImmediate(resolve));
test('the login form stays hidden while checking and an authenticated user goes straight home',async()=>{
    const h=harness();h.hooks.show();
    assert.equal(h.result.checkingSession.value,true);assert.deepEqual(h.home,[]);
    h.session.value={tenant:{id:'tenant'},user:{id:'user'},restricted:false};h.pending().resolve();await tick();
    assert.deepEqual(h.home,['/dashboard']);assert.equal(h.result.checkingSession.value,true);
    assert.match(source,/v-if="checkingSession \|\| sessionCheckFailed"/);
    assert.match(source,/<AuthLayout v-else login/);
});
test('only a confirmed guest sees the login form and restricted users keep their destination',async()=>{
    const guest=harness();guest.hooks.show();guest.session.value={tenant:{id:'tenant'},user:null,restricted:false};guest.pending().resolve();await tick();
    assert.equal(guest.result.checkingSession.value,false);assert.deepEqual(guest.home,[]);
    const limited=harness();limited.hooks.show();limited.session.value={tenant:{id:'tenant'},user:{id:'user'},restricted:true};limited.pending().resolve();await tick();
    assert.deepEqual(limited.home,['/account/restricted']);assert.equal(limited.result.checkingSession.value,true);
});
test('network errors retain the recovery screen and retry without flashing the form',async()=>{
    const h=harness();h.hooks.show();h.pending().reject(Error('network'));await tick();
    assert.equal(h.result.sessionCheckFailed.value,true);assert.equal(h.result.checkingSession.value,true);assert.deepEqual(h.home,[]);
    const retry=h.result.verifySession();h.hooks.show();assert.equal(h.requests.length,2,'a second show cannot duplicate the pending verification');
    h.session.value={tenant:{id:'tenant'},user:{id:'user'},restricted:false};h.pending().resolve();await retry;
    assert.deepEqual(h.home,['/dashboard']);assert.equal(h.result.sessionCheckFailed.value,false);
});
test('a hidden login page cannot navigate or reveal a stale guest result',async()=>{
    const h=harness();h.hooks.show();h.hooks.hide();h.pending().resolve();await tick();
    assert.equal(h.result.checkingSession.value,true);assert.deepEqual(h.home,[]);
});

test('an incomplete success response cannot be mistaken for a confirmed guest',async()=>{
    const h=harness();h.hooks.show();h.session.value={tenant:{id:'tenant'},restricted:false};h.pending().resolve();await tick();
    assert.equal(h.result.checkingSession.value,true);assert.equal(h.result.sessionCheckFailed.value,true);assert.deepEqual(h.home,[]);
});
