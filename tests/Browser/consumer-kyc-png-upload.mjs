import assert from 'node:assert/strict';
import { mkdirSync, readFileSync } from 'node:fs';
import { extname } from 'node:path';
import { chromium, webkit } from 'playwright';

// Selected pixels, API responses and storage validation are synthetic and fully
// intercepted. This never sends a real document, OCR request or provider call.
const fixture = JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json'));
const root = process.env.CONSUMER_H5_TEST_ROOT ?? 'mobile/uni-app/dist/build/h5';
const origin = 'http://127.0.0.1:5239';
const out = 'output/kyc-png-upload';
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAIAAAD91JpzAAAACXBIWXMAAAPoAAAD6AG1e1JrAAAADklEQVQImWP4DwYMEAoAU7oL9W/sIDEAAAAASUVORK5CYII=', 'base64');
const ticketId = side => side === 'front' ? '22222222-2222-4222-8222-222222222222' : '33333333-3333-4333-8333-333333333333';
const cases = [
    ['mislabeled-png', 'selected.png', 'image/jpeg'],
    ['octet-stream-png', 'selected.bin', 'application/octet-stream'],
    ['empty-mime-png', 'selected', ''],
    ['ordinary-png', 'selected.png', 'image/png'],
];
mkdirSync(out, { recursive: true });

for (const [name, engine, options] of [['chrome', chromium, { channel: 'chrome' }], ['webkit', webkit, {}]]) {
    const browser = await engine.launch({ headless: true, ...options });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true });
        await context.addInitScript(() => {
            localStorage.setItem('consumer-bearer-v1', '12|' + 'A'.repeat(64));
            window.__pngBlobReads = {};
            window.__pngMultipartUploads = [];
            const originalFetch = window.fetch.bind(window);
            window.fetch = async (input, ...options) => {
                const url = typeof input === 'string' ? input : input.url;
                if (url?.startsWith('blob:')) window.__pngBlobReads[url] = (window.__pngBlobReads[url] ?? 0) + 1;
                const form = options[0]?.body;
                if (form instanceof FormData) {
                    const file = form.get('file');
                    if (file instanceof Blob) window.__pngMultipartUploads.push({
                        url: new URL(url, location.href).href, mime: file.type,
                        bytes: Array.from(new Uint8Array(await file.arrayBuffer())),
                    });
                }
                return originalFetch(input, ...options);
            };
        });
        const page = await context.newPage();
        page.setDefaultTimeout(10000);
        const errors = [], tickets = [], backups = [], completes = [], submissions = [], mismatches = [], unexpected = [];
        let dto;
        page.on('pageerror', error => errors.push(error.message));
        await context.route('**/*', async route => {
            const request = route.request(), url = new URL(request.url());
            if (['blob:', 'data:'].includes(url.protocol)) return route.continue();
            // The release SDK references this decorative shadow from CSS; keep
            // that static asset offline too, rather than allowing a CDN request.
            if (url.href === 'https://cdn.dcloud.net.cn/img/shadow-grey.png') return route.fulfill({ body: png, contentType: 'image/png' });
            if (url.origin !== origin) { unexpected.push(url.origin); return route.abort(); }
            if (!url.pathname.startsWith('/api/')) {
                if (request.method() !== 'GET') { unexpected.push(request.method() + ' ' + url.pathname); return route.abort(); }
                const types = { '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg' };
                try { return route.fulfill({ body: readFileSync(root + (url.pathname === '/' ? '/index.html' : url.pathname)), contentType: types[extname(url.pathname)] ?? 'text/html' }); }
                catch { return route.abort(); }
            }
            assert.ok(url.pathname.startsWith('/api/mobile/v1/'));
            assert.equal(request.headers().cookie, undefined);
            const path = url.pathname.slice('/api/mobile/v1'.length);
            if (path === '/bootstrap') return route.fulfill({ json: { ...fixture.authenticated, locale: 'en', csrfToken: null } });
            if (path === '/client/kyc') return route.fulfill({ json: dto });
            if (path === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (path === '/presence') return route.fulfill({ status: 204 });
            if (path === '/images/direct') {
                const data = request.postDataJSON();
                assert.equal(data.purpose, 'kyc'); assert.ok(['front', 'back'].includes(data.field));
                tickets.push(data);
                return route.fulfill({ json: { id: ticketId(data.field), mode: 'server', fields: {} } });
            }
            const side = ['front', 'back'].find(side => path.startsWith('/images/direct/' + ticketId(side) + '/'));
            if (side && path.endsWith('/backup')) {
                const file = await page.evaluate(target => window.__pngMultipartUploads.findLast(file => file.url === target), request.url());
                assert.ok(file, 'inspect the actual browser FormData File before transport');
                let body = Buffer.from(file.bytes), uploadedMime = file.mime;
                // Chromium exposes multipart bytes on Request. WebKit omits file
                // bodies there; its actual FormData File is inspected above.
                const bytes = request.postDataBuffer() ?? Buffer.alloc(0);
                const marker = Buffer.from('\r\n\r\n');
                const start = bytes.indexOf(marker) + marker.length;
                const end = bytes.lastIndexOf(Buffer.from('\r\n--'));
                if (start >= marker.length && end > start) {
                    const sent = bytes.subarray(start, end);
                    const headers = bytes.subarray(0, start).toString('utf8');
                    const sentMime = headers.match(/Content-Type: ([^\r\n]+)/i)?.[1];
                    assert.ok(sent.equals(body)); assert.equal(sentMime, uploadedMime);
                    body = sent; uploadedMime = sentMime;
                } else assert.equal(name, 'webkit', 'Chromium must expose the serialized multipart file for a second independent check');
                const ticket = tickets.findLast(ticket => ticket.field === side);
                backups.push({ side, mime: uploadedMime, bytes: body });
                // Match the server's byte-derived MIME guard; client labels cannot
                // turn PNG bytes into JPEG. No permissive fixture-only fallback.
                if (!body.equals(png) || ticket.mime !== 'image/png') {
                    mismatches.push({ side, ticketMime: ticket.mime, actualMime: body.subarray(0, 8).equals(png.subarray(0, 8)) ? 'image/png' : 'invalid' });
                    return route.fulfill({ status: 422, json: { error: { code: 'IMAGE_INVALID', message: 'Unsupported image format.', request_id: '11111111-1111-4111-8111-111111111111' } } });
                }
                return route.fulfill({ status: 204 });
            }
            if (side && path.endsWith('/complete')) { completes.push(side); return route.fulfill({ status: 204 }); }
            if (path === '/client/kyc/applications') {
                submissions.push(request.postDataJSON());
                Object.assign(dto.props, { canSubmit: false });
                Object.assign(dto.props.kyc, { status: 'PENDING', processingStatus: 'QUEUED', processingError: null });
                return route.fulfill({ status: 202, json: { applicationId: 'offline-application', processingStatus: 'QUEUED', success: 'Your identity documents were submitted for review.' } });
            }
            if (request.method() !== 'GET') { unexpected.push(request.method() + ' ' + path); return route.abort(); }
            return route.fulfill({ json: fixture.api[path] ?? {} });
        });
        for (const [label, filename, mimeType] of cases) {
            tickets.length = backups.length = completes.length = submissions.length = mismatches.length = 0;
            dto = structuredClone(fixture.pages['/kyc']);
            Object.assign(dto.props, { canSubmit: true, canReverify: false, maxDocumentMb: 5 });
            Object.assign(dto.props.kyc, { status: 'NOT_SUBMITTED', processingStatus: null, processingError: null });
            await page.goto(origin + '/#/pages/screen/index?path=%2Fkyc');
            await page.reload();
            await page.getByText('Submit identity documents', { exact: true }).waitFor();
            for (const side of ['ID front', 'ID back']) {
                const chooser = page.waitForEvent('filechooser');
                await page.locator('.upload-field').filter({ has: page.getByText(side, { exact: true }) }).locator('.upload-control').click();
                await (await chooser).setFiles({ name: filename, mimeType, buffer: png });
            }
            assert.deepEqual(tickets, [], 'selecting photos does not prepare uploads or call OCR');
            await page.locator('.submit-button').click();
            const queued = page.getByText('Documents received. Processing continues in the background; you will be notified of the result.', { exact: true });
            const alert = page.locator('.kyc-card .form-errors');
            await Promise.race([queued.waitFor(), alert.waitFor()]);
            if (await alert.count()) await page.screenshot({ path: `${out}/${name}-${label}-error.png`, fullPage: true });
            assert.deepEqual(mismatches, [], `${label}: declared upload MIME must match the retained original PNG bytes`);
            assert.equal(await alert.count(), 0, `${label}: actual PNG must not show unsupported-image errors`);
            await queued.waitFor();
            assert.deepEqual(tickets.map(ticket => [ticket.field, ticket.mime]), [['front', 'image/png'], ['back', 'image/png']]);
            assert.deepEqual(backups.map(backup => [backup.side, backup.mime]), [['front', 'image/png'], ['back', 'image/png']]);
            assert.ok(backups.every(backup => backup.bytes.equals(png)), 'normalizing MIME preserves every selected byte');
            assert.deepEqual(completes, ['front', 'back']); assert.equal(submissions.length, 1);
            assert.equal(submissions[0].front_upload_id, ticketId('front')); assert.equal(submissions[0].back_upload_id, ticketId('back'));
            assert.equal('front_url' in submissions[0], false); assert.equal('identity_number' in submissions[0], false);
            const reads = await page.evaluate(() => Object.values(window.__pngBlobReads));
            assert.deepEqual(reads, [1, 1], 'each selected original Blob is fetched once and reused for transport');
            if (label === 'mislabeled-png') await page.screenshot({ path: `${out}/${name}-${label}-queued.png`, fullPage: true });
        }
        assert.deepEqual(unexpected, []); assert.deepEqual(errors, []);
        console.log(`${name}: real PNG bytes with JPEG/empty/octet-stream/PNG metadata receive PNG tickets, preserve exact bytes and normalized multipart type, reuse each Blob once, and submit once without OCR (offline fixtures).`);
        await context.close();
    } finally { await browser.close(); }
}
