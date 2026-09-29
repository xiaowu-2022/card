import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import ts from 'typescript';
const source = ts.transpileModule(readFileSync('mobile/uni-app/src/lib/domain-routing.ts', 'utf8'), {
    compilerOptions: { module: ts.ModuleKind.ES2022, target: ts.ScriptTarget.ES2022 },
}).outputText;
const { DomainRouter, normalizeOrigin } = await import('data:text/javascript;base64,' + Buffer.from(source).toString('base64'));
const a = 'https://a.example.org', b = 'https://b.example.org', c = 'https://c.example.org';
const directory = (origins, slug = 'company', id = 'id') => ({ tenant: { id, slug }, origins });
const pause = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

test('syncs full directory, measures newly discovered domains and picks the fastest', async () => {
    let saved;
    const calls = [];
    const router = new DomainRouter([a], 'company', false, async (origin) => {
        calls.push(origin);
        await pause(origin === a ? 35 : origin === b ? 20 : 1);
        return directory([a, b, c]);
    }, [], (value) => { saved = value; });
    await Promise.all([router.ready(), router.refresh(), router.ready()]);
    assert.equal(router.selected, c);
    assert.deepEqual(saved, [a, b, c]);
    assert.deepEqual(calls.sort(), [a, b, c]);
});
test('cached domain recovers unavailable seeds and refreshed list removes stale domains', async () => {
    const router = new DomainRouter([a], 'company', false, async (origin) => {
        if (origin === a) throw new Error('offline');
        return directory([b]);
    }, [b, c], () => {});
    await router.ready();
    assert.equal(router.selected, b);
});
test('rejects wrong companies, malformed replies and all-offline state; later retry recovers', async () => {
    let recovered = false;
    const router = new DomainRouter([a, b, c], 'company', false, async (origin) => {
        if (recovered) return directory([a, b, c]);
        if (origin === a) return directory([a], 'other');
        if (origin === b) return { tenant: { id: 'id', slug: 'company' }, origins: ['http://b.example.org'] };
        throw new Error('offline');
    }, [], () => {});
    await assert.rejects(router.ready());
    assert.equal(router.selected, null);
    recovered = true;
    await router.ready();
    assert.ok(router.selected);
});
test('foreground refresh removes old selections and pins tenant identity across domain reassignment', async () => {
    let reassigned = false;
    const router = new DomainRouter([a], 'company', false, async () => directory([a], 'company', reassigned ? 'other-id' : 'id'), [], () => {});
    await router.ready();
    reassigned = true;
    await assert.rejects(router.refresh());
    assert.equal(router.selected, null);
});
test('many domains use bounded concurrency without truncating the directory', async () => {
    const origins = Array.from({ length: 25 }, (_, index) => `https://d${index}.example.org`);
    let active = 0, maximum = 0, calls = 0;
    const router = new DomainRouter([origins[0]], 'company', false, async () => {
        active++; maximum = Math.max(active, maximum); calls++;
        await pause(1); active--;
        return directory(origins);
    }, [], () => { throw new Error('storage full'); });
    await router.ready();
    assert.equal(calls, 25);
    assert.ok(maximum <= 6);
});
test('accepts only clean HTTPS origins outside development', () => {
    for (const value of ['http://a.org', 'https://user:secret@a.org', 'https://a.org/path', 'https://a.org?x=1', 'https://a.org#x', 'https://a.org\\evil', 'https://a.org:99999', 'https://a..org', null]) assert.equal(normalizeOrigin(value), null);
    assert.equal(normalizeOrigin('HTTPS://A.ORG/'), 'https://a.org');
    assert.equal(normalizeOrigin('http://a.localhost:8000', true), 'http://a.localhost:8000');
});
