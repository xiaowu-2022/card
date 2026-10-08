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
        const uni = {
            getLocale: () => 'en',
            request(options) {
                state.calls.push(options);
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
