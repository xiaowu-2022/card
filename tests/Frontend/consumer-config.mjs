import test from 'node:test';
import assert from 'node:assert/strict';
import { validateCompany, readCompany } from '../../scripts/client/config.mjs';
const profile = { name: 'Example', appId: 'cc.example.cards', dcloudAppId: '__UNI__ABC1234', tenantSlug: 'company-a', apiOrigin: 'https://cards.example.org', version: '1.0.0', buildNumber: 1, developmentOnly: false };
test('keeps debug packages separate and release domains fixed', () => {
    assert.equal(validateCompany(profile).appId, 'cc.example.cards.debug');
    assert.equal(validateCompany(profile, 'release').appId, 'cc.example.cards');
});
test('rejects insecure or incomplete release profiles', () => {
    for (const change of [{ apiOrigin: 'http://cards.example.org' }, { apiOrigin: 'https://a.localhost' }, { dcloudAppId: '' }, { developmentOnly: true }, { apiOrigin: 'https://secret:password@cards.example.org' }, { apiOrigin: 'https://cards.example.org/login' }]) assert.throws(() => validateCompany({ ...profile, ...change }, 'release'));
});
test('rejects traversal, unsafe names, secrets and invalid versions', () => {
    assert.throws(() => readCompany(process.cwd(), '../../etc/passwd'));
    for (const change of [{ name: '<script>' }, { privateKey: 'secret' }, { appId: 'bad;command' }, { version: '1.0' }, { buildNumber: -1 }]) assert.throws(() => validateCompany({ ...profile, ...change }));
});
