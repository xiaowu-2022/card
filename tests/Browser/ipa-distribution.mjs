import assert from 'node:assert/strict';
import { chromium, webkit } from 'playwright';
import { spawnSync } from 'node:child_process';
import { existsSync, unlinkSync } from 'node:fs';
const origin = 'http://127.0.0.1:8000';
const files = ['distribution-test U卡.ipa', 'distribution-test-broken.ipa'];
const paths = files.map(name => new URL('../../public/ipa/' + name, import.meta.url));
assert.ok(paths.every(path => !existsSync(path)), 'Test fixture filenames must be unused');
try {
    let response = await fetch(origin + '/ipa/index.php');
    assert.equal(response.status, 200);
    assert.match(response.headers.get('cache-control'), /no-store/);
    assert.match(await response.text(), /安装包准备中/);
    const setup = spawnSync('python3', ['-c', `import pathlib,zipfile,json,base64
root=pathlib.Path('public/ipa')
fixtures=json.loads(pathlib.Path('tests/Fixtures/ipa-plists.json').read_text())
with zipfile.ZipFile(root/'distribution-test U卡.ipa','x') as z:z.writestr('Payload/Example.app/Info.plist',base64.b64decode(fixtures['binary']))
with (root/'distribution-test-broken.ipa').open('x') as f:f.write('incomplete')
`], { encoding: 'utf8' });
    assert.equal(setup.status, 0, setup.stderr);
    response = await fetch(origin + '/ipa/index.php?manifest=' + encodeURIComponent(files[0]));
    assert.equal(response.status, 200);
    const manifest = await response.text();
    assert.match(manifest, /https:\/\/127\.0\.0\.1:8000\/ipa\/distribution-test%20U%E5%8D%A1\.ipa/);
    assert.match(manifest, /<key>bundle-version<\/key><string>42<\/string>/);
    for (const file of ['../index.php', files[1], 'missing.ipa']) {
        assert.equal((await fetch(origin + '/ipa/index.php?manifest=' + encodeURIComponent(file))).status, 404);
    }
    assert.equal((await fetch(origin + '/ipa/index.php?manifest[]=x')).status, 404);
    assert.equal((await fetch(origin + '/ipa/distribution.php')).status, 404);
    assert.equal((await fetch(origin + '/ipa/')).status, 200);
    const chrome = '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
    for (const [name, engine] of [['chromium', chromium], ['webkit', webkit]]) {
        const browser = await engine.launch({ headless: true, ...(name === 'chromium' && existsSync(chrome) ? { executablePath: chrome } : {}) });
        try {
            const context = await browser.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 Version/18.0 Mobile/15E148 Safari/604.1' });
            const page = await context.newPage();
            await page.route('https://ipa.example.test/**', async route => {
                const upstream = await fetch(origin + new URL(route.request().url()).pathname + new URL(route.request().url()).search);
                await route.fulfill({ status: upstream.status, headers: Object.fromEntries(upstream.headers), body: Buffer.from(await upstream.arrayBuffer()) });
            });
            await page.goto('https://ipa.example.test/ipa/');
            assert.equal(await page.locator('.package').count(), 1);
            assert.equal(await page.locator('.details h2').textContent(), '测试 U卡 & <示例>');
            assert.match(await page.locator('.details').textContent(), /2\.1\.0.*42/);
            assert.equal(await page.locator('.details script').count(), 0);
            const link = await page.locator('.install').getAttribute('href');
            assert.match(link, /^itms-services:\/\/\?action=download-manifest&url=/);
            assert.equal(new URL(decodeURIComponent(link.split('&url=')[1])).protocol, 'https:');
            await page.locator('.install').evaluate(el => el.addEventListener('click', e => e.preventDefault()));
            await page.locator('.install').click();
            await page.waitForFunction(() => !document.querySelector('#install-status').hidden);
            assert.match(await page.locator('#install-status').textContent(), /已请求系统/);
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No mobile horizontal overflow');
            await page.screenshot({ path: '/tmp/card-ipa-distribution-' + name + '.png', fullPage: true });
            await page.goto(origin + '/ipa/');
            await page.locator('.install').click();
            assert.match(await page.locator('#install-status').textContent(), /HTTPS/);
            console.log('PASS', name, 'mobile layout, install URL, escaping and HTTP guard');
        } finally { await browser.close(); }
    }
    console.log('PASS empty state, real manifest, missing/corrupt package, traversal and direct helper protection');
} finally {
    for (const path of paths) if (existsSync(path)) unlinkSync(path);
}
