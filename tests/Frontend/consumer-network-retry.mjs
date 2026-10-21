import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';

let sequence = 0;
async function harness(native = true) {
    const state = { calls: [], refreshes: 0, outcomes: [], selected: 'https://first.example.org', refresh: null };
    globalThis.__networkTest = state;
    let source = readFileSync('mobile/uni-app/src/lib/api.ts', 'utf8')
        .replace(/^import .*;$/gm, '')
        .replaceAll("import.meta.env.UNI_PLATFORM", JSON.stringify(native ? 'app' : 'h5'));
    source = `
        const state = globalThis.__networkTest;
        const company = {};
        const companyOrigin = () => state.selected;
        const ensureCompanyOrigin = async () => {};
        const refreshCompanyOrigins = async () => {
            state.refreshes++;
            state.selected = 'https://second.example.org';
            await state.refresh?.();
        };
        const androidCredentialVault = () => null;
        const loadBrowserToken = async () => null, saveBrowserToken = () => {}, publishBrowserToken = async () => {};
        const fetch = async (url, options) => new Promise((resolve,reject) => uni.request({
            url, method:options.method, header:options.headers, withCredentials:false,
            fail:reject, success:r=>resolve(new Response(state.rawResponse ?? JSON.stringify(r.data),{status:r.statusCode,headers:r.header})),
        }));
        const uni = {
            getLocale: () => 'en',
            request(options) {
                state.calls.push(options);
                if (state.transport) return state.transport(options);
                const outcome = state.outcomes.shift();
                if (outcome === 0) options.fail();
                else options.success({statusCode: outcome ?? 200, data: {ok:true}, header:{}});
            }
        };
        ${source}
    `;
    const js = ts.transpileModule(source, {compilerOptions:{module:ts.ModuleKind.ES2022,target:ts.ScriptTarget.ES2022}}).outputText;
    globalThis.plus = {os:{name:'iOS'}};
    const api = await import('data:text/javascript;base64,' + Buffer.from(js + `\n// ${sequence++}`).toString('base64'));
    return {state, api};
}
for (const failure of [0, 502, 503, 504]) test(`native GET recovers from ${failure} through a freshly verified origin`, async () => {
    const {state, api} = await harness();
    state.outcomes = [failure,200];
    assert.deepEqual(await api.request('/client/kyc'),{ok:true});
    assert.equal(state.refreshes,1);
    assert.equal(state.calls.length,2);
    assert.ok(state.calls[1].url.startsWith('https://second.example.org/'));
});
test('never retries POST, authorization failures, or browser requests', async () => {
    for (const [native,method,status] of [[true,'POST',0],[true,'POST',503],[true,'GET',403],[true,'GET',401],[false,'GET',0]]) {
        const {state,api} = await harness(native);
        state.outcomes=[status];
        await assert.rejects(api.request('/client/transfer',method));
        assert.equal(state.calls.length,1);
        assert.equal(state.refreshes,0);
    }
});
test('second failure surfaces to the page without a retry loop', async () => {
    const {state,api}=await harness();
    state.outcomes=[0,0];
    await assert.rejects(api.request('/client/kyc'));
    assert.equal(state.calls.length,2);
    assert.equal(state.refreshes,1);
});
test('session change during discovery cancels replay', async () => {
    const {state,api}=await harness();
    state.outcomes=[0];
    state.refresh=()=>api.setToken(null);
    await assert.rejects(api.request('/client/kyc'));
    assert.equal(state.calls.length,1);
});

test('concurrent equivalent GETs share transport and deliver callbacks; settled reads are fresh', async () => {
    const {state, api} = await harness(false);
    let release;
    state.transport = options => { release = () => options.success({statusCode:200,data:{ok:true},header:{}}); };
    const seen = [];
    const a = api.request('/assets', 'GET', {b:2,a:1}, status => seen.push(status));
    const b = api.request('/assets', 'GET', {a:1,b:2}, status => seen.push(status));
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(state.calls.length, 1);
    release();
    await Promise.all([a,b]);
    assert.deepEqual(seen, [200,200]);
    state.transport = null;
    await api.request('/assets');
    assert.equal(state.calls.length, 2);
});
test('page, language, parameters and identity isolate reads; stale identity rejects', async () => {
    const {state, api} = await harness(false);
    const releases = [];
    state.transport = options => releases.push(() => options.success({statusCode:200,data:{ok:true},header:{}}));
    const reads = [api.request('/assets').catch(e=>e.status)];
    await new Promise(resolve => setTimeout(resolve, 10));
    api.setCurrentPage('/cards'); reads.push(api.request('/assets').catch(e=>e.status));
    api.setLanguage('ms'); reads.push(api.request('/assets?asset=USDT').catch(e=>e.status));
    await new Promise(resolve => setTimeout(resolve, 10));
    api.setToken(null); reads.push(api.request('/assets'));
    await new Promise(resolve => setTimeout(resolve, 10));
    assert.equal(state.calls.length, 4);
    releases.forEach(release=>release());
    const results = await Promise.all(reads);
    assert.deepEqual(results.slice(0,3), [409,409,409]);
    assert.deepEqual(results[3], {ok:true});
});
test('failed shared GET is removed and POSTs remain independent', async () => {
    const {state, api} = await harness(false);
    state.outcomes = [503];
    await Promise.all([assert.rejects(api.request('/assets')), assert.rejects(api.request('/assets'))]);
    assert.equal(state.calls.length, 1);
    await api.request('/assets');
    await Promise.all([api.request('/presence','POST'),api.request('/presence','POST')]);
    assert.equal(state.calls.length, 4);
});

test('browser rejects malformed JSON, empty successful bodies and all gateway failures without replay', async () => {
    for (const raw of ['<html>Bad gateway</html>', '', 'null']) {
        const {state,api} = await harness(false);
        state.rawResponse = raw;
        await assert.rejects(api.request('/assets'), error=>error.status === 502);
        assert.equal(state.calls.length,1);
    }
    for (const status of [500,502,503,504]) {
        const {state,api}=await harness(false); state.outcomes=[status];
        await assert.rejects(api.request('/assets'),error=>error.status===status);
        assert.equal(state.calls.length,1);
    }
});
