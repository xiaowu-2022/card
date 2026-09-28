import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

function client(platform, base = '/') {
    const cache = new Map();
    const window = { location: { origin: 'https://alternate.example.org', href: 'https://alternate.example.org/' } };
    function load(path) {
        if (path.endsWith('company.json')) return { apiOrigin: 'https://primary.example.org' };
        if (cache.has(path)) return cache.get(path);
        const exports = {};
        cache.set(path, exports);
        const source = readFileSync(path, 'utf8').replaceAll('import.meta.env.UNI_PLATFORM', JSON.stringify(platform)).replaceAll('import.meta.env.BASE_URL', JSON.stringify(base));
        const compiled = ts.transpileModule(source, {
            compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true },
        }).outputText;
        runInNewContext(compiled, { exports, window, URL, require: (id) => load(resolve(dirname(path), id + (id.endsWith('.json') ? '' : '.ts'))) });
        return exports;
    }
    const sourceDir = resolve('mobile/uni-app/src/lib');
    return { window, origin: load(resolve(sourceDir, 'origin.ts')), api: load(resolve(sourceDir, 'api.ts')), navigation: load(resolve(sourceDir, 'navigation.ts')) };
}

test('H5 follows the current domain for links, image paths and internal navigation', () => {
    const c = client('h5');
    for (const domain of ['https://alternate.example.org', 'https://second.example.org']) {
        c.window.location.origin = domain;
        assert.equal(c.origin.companyOrigin(), domain);
        assert.equal(c.api.photoUrl(domain + '/storage/image.png'), '/storage/image.png');
        assert.equal(c.navigation.internalUrl(domain + '/login'), '/pages/login/index');
        assert.throws(() => c.navigation.internalUrl('https://primary.example.org/login'));
        assert.equal(c.api.photoUrl('https://unrelated.example.org/image.png'), '');
    }
});

test('subdirectory H5 assets and invitation links remain inside the deployed H5', () => {
    const c = client('h5', '/h5/');
    assert.equal(c.origin.staticAsset('icons/Bell.svg'), '/h5/static/icons/Bell.svg');
    const invite = new URL(c.origin.invitationUrl('test+code'));
    assert.equal(invite.origin, c.window.location.origin);
    assert.equal(invite.pathname, '/h5/');
    assert.equal(new URLSearchParams(invite.hash.split('?')[1]).get('path'), '/register?invite=test%2Bcode');
    const native = client('app', '/h5/');
    assert.equal(native.origin.staticAsset('icons/Bell.svg'), '/static/icons/Bell.svg');
    assert.equal(native.origin.invitationUrl('test'), 'https://primary.example.org/register?invite=test');
});

test('native App retains its configured domain even when a browser-like global exists', () => {
    const c = client('app');
    assert.equal(c.origin.companyOrigin(), 'https://primary.example.org');
    assert.equal(c.api.photoUrl('/storage/image.png'), 'https://primary.example.org/storage/image.png');
    assert.equal(c.navigation.internalUrl('https://primary.example.org/login'), '/pages/login/index');
    assert.throws(() => c.navigation.internalUrl('https://alternate.example.org/login'));
});

test('one relative build follows root, renamed directories and explicit index entry points', () => {
    const c = client('h5', './');
    for (const [entry, base] of [['/', '/'], ['/client/', '/client/'], ['/another/nested/index.html', '/another/nested/']]) {
        c.window.location.href = c.window.location.origin + entry + '#/pages/login/index';
        assert.equal(c.origin.webBase(), base);
        assert.equal(c.origin.staticAsset('icons/Bell.svg'), base + 'static/icons/Bell.svg');
        assert.equal(new URL(c.origin.invitationUrl('test')).pathname, base);
    }
});
