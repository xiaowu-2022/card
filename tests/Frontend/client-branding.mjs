import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, rm, readFile } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import sharp from 'sharp';
import { prepareBranding } from '../../scripts/client/branding.mjs';
const config = { apiOrigins: ['https://zb33333.com', 'https://api.example'], tenantSlug: 'demo', developmentOnly: false };
const logo = await sharp({ create: { width: 320, height: 320, channels: 3, background: '#ff0000' } }).png().toBuffer();
function fake(patch = {}) {
    return async (url, options) => {
        assert.equal(options.headers.Authorization, undefined);
        assert.ok(!url.includes('zb33333'));
        if (url.endsWith('/bootstrap')) return Response.json({ tenant: { id: 'tenant-demo', slug: 'demo', apkLogoUrl: '/logo.png', ...patch } });
        return new Response(logo);
    };
}
test('generates correctly sized opaque icons and centered splash screens from verified online branding', async () => {
    const dir = await mkdtemp(join(tmpdir(), 'card-branding-'));
    try {
        const result = await prepareBranding(config, dir, fake());
        for (const path of [...Object.values(result.icons.android), ...Object.values(result.splashscreen.android)]) {
            assert.ok(path.startsWith('src/static/native-branding/'));
            assert.equal((await sharp(join(dir, path)).metadata()).format, 'png');
        }
        const icon = await sharp(join(dir, result.icons.android.xxxhdpi)).metadata();
        assert.equal(icon.width, 192); assert.equal(icon.height, 192); assert.equal(icon.hasAlpha, false);
        const splash = await sharp(join(dir, result.splashscreen.android.xxhdpi)).metadata();
        assert.equal(splash.width, 1080); assert.equal(splash.height, 1882);
        const source = JSON.parse(await readFile(join(dir, 'src/static/native-branding/source.json')));
        assert.equal(source.tenantSlug, 'demo'); assert.match(source.sha256, /^[a-f0-9]{64}$/);
        assert.equal(result.splashscreen.androidStyle, 'default');
        const { data, info } = await sharp(join(dir, result.splashscreen.android.hdpi)).raw().toBuffer({ resolveWithObject: true });
        assert.deepEqual([...data.subarray(0, 3)], [255, 255, 255]);
        const center = (Math.floor(info.height / 2) * info.width + Math.floor(info.width / 2)) * info.channels;
        assert.deepEqual([...data.subarray(center, center + 3)], [255, 0, 0]);
    } finally { await rm(dir, { recursive: true, force: true }); }
});
for (const patch of [{ slug: 'other' }, { apkLogoUrl: null }, { apkLogoUrl: 'file:///etc/passwd' }]) {
    test('rejects wrong company, missing logo or unsafe image URL ' + JSON.stringify(patch), async () => {
        await assert.rejects(prepareBranding(config, '/unused', fake(patch)), /Native packaging stopped/);
    });
}
test('tries alternate API seeds after outage and rejects invalid image bytes', async () => {
    let count = 0;
    await assert.rejects(prepareBranding({ ...config, apiOrigins: ['https://one.example', 'https://two.example'] }, '/unused', async url => {
        if (url.includes('one.example')) { count++; throw new Error('offline'); }
        if (url.endsWith('/bootstrap')) return Response.json({ tenant: { id: 'tenant-demo', slug: 'demo', apkLogoUrl: '/logo' } });
        return new Response('not an image');
    }), /Native packaging stopped/);
    assert.equal(count, 1);
});
