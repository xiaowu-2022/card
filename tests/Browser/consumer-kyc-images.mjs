import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5217';
const png = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=',
    'base64',
);
mkdirSync('artifacts/uni-parity/kyc-images', { recursive: true });
for (const [name, engine, options] of [
    ['chromium', chromium, { channel: 'chrome' }],
    ['webkit', webkit, {}],
]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({
            viewport: { width: 390, height: 750 },
            isMobile: true,
        });
        const page = await context.newPage();
        const dto = structuredClone(fixture.pages['/kyc?fixture=verified']);
        Object.assign(dto.props.kyc, {
            status: 'APPROVED',
            documentType: 'NATIONAL_ID',
            frontSources: [
                origin + '/mock/front?x-oss-process=thumbnail',
                origin + '/mock/front',
                origin + '/mock/front-replica',
            ],
            frontOriginalSources: [origin + '/mock/front', origin + '/mock/front-replica'],
            backSources: [
                origin + '/mock/back?x-oss-process=thumbnail',
                origin + '/mock/back',
                origin + '/mock/back-replica',
            ],
            backOriginalSources: [origin + '/mock/back', origin + '/mock/back-replica'],
        });
        const requests = [],
            mutations = [],
            errors = [];
        let mode = 'ok',
            reads = 0;
        page.on('pageerror', (e) => errors.push(e.message));
        await context.route('**/*', (route) => {
            const req = route.request(),
                u = new URL(req.url());
            if (u.pathname.startsWith('/mock/')) {
                requests.push(u.pathname + u.search);
                const fail =
                    mode === 'fail' ||
                    (mode === 'fallback' && (u.search || !u.pathname.endsWith('replica')));
                return route.fulfill({
                    status: fail ? 503 : 200,
                    contentType: 'image/png',
                    headers: { 'cache-control': 'no-store' },
                    body: fail ? '' : png,
                });
            }
            if (u.pathname.startsWith('/api/v1')) {
                if (req.method() !== 'GET') {
                    mutations.push(u.pathname);
                    return route.fulfill({ json: {} });
                }
                const key = u.pathname.slice(7);
                if (key === '/bootstrap')
                    return route.fulfill({ json: { ...fixture.authenticated, locale: 'en' } });
                if (key === '/client/kyc') {
                    reads++;
                    return route.fulfill({ json: dto });
                }
                if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
                return route.fulfill({ json: fixture.api[key] ?? {} });
            }
            if (u.origin !== origin || req.method() !== 'GET') return route.abort();
            return route.continue();
        });
        await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
        const photos = page.locator('.document-photo');
        await photos.first().waitFor();
        await page.waitForFunction(
            () =>
                [...document.querySelectorAll('.document-photo > .retry-image uni-image')].every(
                    (e) => e.style.opacity === '1',
                ) && document.querySelectorAll('.document-photo').length === 2,
        );
        assert.ok(
            requests.every((v) => v.includes('x-oss-process')),
            JSON.stringify(requests),
        );
        const frame = await photos.first().locator('.image-frame').boundingBox();
        assert.ok(frame.height <= 115, JSON.stringify(frame));
        await photos.first().locator('.image-frame').click();
        const dialog = page.locator('[role="dialog"]');
        await dialog.waitFor();
        await page.waitForFunction(
            () =>
                document.querySelector('[role="dialog"] .retry-image uni-image')?.style.opacity ===
                '1',
        );
        assert.ok(requests.includes('/mock/front'));
        await dialog.locator('[aria-label="Close"]').click();
        mode = 'fallback';
        requests.length = 0;
        for (const key of [
            'frontSources',
            'backSources',
            'frontOriginalSources',
            'backOriginalSources',
        ])
            dto.props.kyc[key] = dto.props.kyc[key].map((u) =>
                u.replace('/mock/', '/mock/fallback/'),
            );
        await page.reload();
        await page.waitForFunction(() =>
            document
                .querySelector('.document-photo uni-image img')
                ?.getAttribute('src')
                ?.endsWith('front-replica'),
        );
        await page.waitForFunction(
            () => document.querySelector('.document-photo uni-image')?.style.opacity === '1',
        );
        assert.deepEqual(
            requests.filter((v) => v.includes('front')),
            [
                '/mock/fallback/front?x-oss-process=thumbnail',
                '/mock/fallback/front',
                '/mock/fallback/front-replica',
            ],
        );
        await photos.first().locator('.document-open').click();
        await dialog.waitFor();
        await page.waitForFunction(() =>
            document
                .querySelector('[role="dialog"] .retry-image uni-image img')
                ?.getAttribute('src')
                ?.endsWith('front-replica'),
        );
        await page.waitForFunction(
            () =>
                document.querySelector('[role="dialog"] .retry-image uni-image')?.style.opacity ===
                '1',
        );
        await page.screenshot({ path: `artifacts/uni-parity/kyc-images/${name}-original.png` });
        await dialog.locator('[aria-label="Close"]').click();
        mode = 'fail';
        requests.length = 0;
        for (const key of [
            'frontSources',
            'backSources',
            'frontOriginalSources',
            'backOriginalSources',
        ])
            dto.props.kyc[key] = dto.props.kyc[key].map((u) => u.replace('/fallback/', '/fail/'));
        await page.reload();
        await photos.first().getByText('Image unavailable', { exact: true }).waitFor();
        const count = requests.length;
        await page.waitForTimeout(400);
        assert.equal(requests.length, count, 'no failure loop');
        await page.screenshot({ path: `artifacts/uni-parity/kyc-images/${name}-failure.png` });
        mode = 'ok';
        await photos.first().locator('.image-refresh').click();
        await page.waitForFunction(
            () => document.querySelector('.document-photo uni-image')?.style.opacity === '1',
        );
        assert.ok(reads >= 4, 'refresh renews server URLs through read only GET');
        assert.deepEqual(errors, []);
        assert.ok(
            mutations.every((v) => v === '/api/v1/wallet/ensure'),
            JSON.stringify(mutations),
        );
        console.log(
            'PASS ' +
                name +
                ': thumbnail, lazy original, bounded fallback, visible failure, refresh',
        );
    } finally {
        await browser.close();
    }
}
