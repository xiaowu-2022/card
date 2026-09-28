import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')),
);
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5202';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const id = '11111111-1111-4111-8111-111111111111';
const out = 'artifacts/uni-parity/acceptance';
mkdirSync(out, { recursive: true });
async function scenario(
    name,
    path,
    run,
    mutate = () => ({
        status: 503,
        json: { error: { message: 'Unable to load. Please try again.' } },
    }),
    prepare = () => {},
    locale = 'en',
    width = 375,
) {
    const context = await browser.newContext({ viewport: { width, height: 900 } }),
        page = await context.newPage(),
        posts = [],
        errors = [];
    let dto = structuredClone(fixture.pages[path]);
    assert.ok(dto, path);
    dto.props.i18n.locale = locale;
    const boot = structuredClone(dto.props.auth?.user ? fixture.authenticated : fixture.guest);
    boot.locale = locale;
    if (name === 'withdrawal-review') dto.props.feePercent = '10';
    const state = { dto, boot };
    prepare(state);
    page.on('pageerror', (e) => errors.push(e.message));
    await context.route('**/*', async (route) => {
        const req = route.request(),
            u = new URL(req.url()),
            key = u.pathname.replace(/^\/api\/v1/, '');
        if (u.pathname.startsWith('/api/v1')) {
            if (req.method() !== 'GET') {
                const payload = req.headers()['content-type']?.includes('multipart/form-data')
                    ? { multipart: req.postData() }
                    : req.postDataJSON();
                posts.push({ key, payload });
                return route.fulfill(mutate(key, payload, state) ?? { json: { success: true } });
            }
            if (key === '/client/promotion/poster-background') return route.fulfill({ contentType: 'image/jpeg', body: readFileSync('public/images/marketing/growth/poster-770.jpg') });
            if (key === '/bootstrap') return route.fulfill({ json: state.boot });
            if (key === '/support') return route.fulfill({ json: { messages: [], olderCursor: null } });
            if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
            if (key.startsWith('/client')) return route.fulfill({ json: state.dto });
            return route.fulfill({ status: 404, json: {} });
        }
        if (req.method() !== 'GET' || u.origin !== origin) return route.abort();
        return route.continue();
    });
    const target = path === '/support' ? '/pages/support/index' : path === '/account' ? '/pages/account/index' : path === '/dashboard' ? '/pages/assets/index' : path === '/messages' ? '/pages/messages/index' : path.startsWith('/cards')
        ? '/pages/cards/index'
        : '/pages/screen/index?path=' + encodeURIComponent(path);
    await page.goto(origin + '/#' + target);
    await page
        .locator('uni-page-body .user-root, uni-page-body .auth-root')
        .first()
        .waitFor({ timeout: 15000 })
        .catch(() => {});
    try {
        await run({ page, posts, state });
    } catch (e) {
        await page.screenshot({ path: out + '/' + name + '-failed.png', fullPage: true });
        throw e;
    }
    assert.deepEqual(errors, [], name);
    await page.screenshot({ path: out + '/' + name + '.png', fullPage: true });
    await context.close();
    console.log('PASS ' + name);
}
const button = (page, text) =>
    page.locator('uni-button').filter({ hasText: new RegExp('^' + text + '$') });
const field = (page, label) =>
    page
        .locator('.form-field')
        .filter({
            has: page.locator('.form-label').filter({ hasText: new RegExp('^' + label + '$') }),
        })
        .locator('input');
const failure = () => ({ status: 503, json: { error: { message: 'Unable to load. Please try again.' } } });
const waitPost = async (posts, count) => {
    for (let i = 0; i < 100 && posts.length < count; i++) await new Promise(r => setTimeout(r, 50));
    assert.equal(posts.length, count);
};
const confirm = async page => {
    await field(page, 'Current password').fill('synthetic-only');
    if (await page.locator('.confirm-check .uni-checkbox-input svg').count() === 0)
        await page.locator('.confirm-check').click();
    await page.locator('.modal-panel uni-button.primary').click();
};
try {
    await scenario('deposit-funding-retry', '/security-deposit?fixture=verified', async ({ page, posts }) => {
        await button(page, 'Confirm deposit').click();
        await waitPost(posts, 1);
        await page.locator('.form-errors').waitFor();
        await button(page, 'Confirm deposit').click();
        await waitPost(posts, 2);
        assert.equal(posts[0].key, '/client/security-deposit/fund');
        assert.equal(posts[0].payload.expected_remaining, '100.00000000');
        assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
    });
    for (const cancel of [false, true]) await scenario('deposit-refund-' + (cancel ? 'cancel' : 'request'), '/security-deposit?fixture=verified', async ({ page, posts }) => {
        await button(page, cancel ? 'Cancel deposit refund request' : 'Request deposit refund').click();
        assert.equal(posts.length, 0);
        assert.notEqual(await page.locator('.modal-panel uni-button.primary').getAttribute('disabled'), null);
        await confirm(page);
        await waitPost(posts, 1);
        await page.waitForFunction(() => [...document.querySelectorAll('input')].every(i => i.value !== 'synthetic-only'));
        assert.equal(posts[0].payload.action, cancel ? 'cancel' : 'request');
        await confirm(page); await waitPost(posts, 2);
        assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
        if (cancel) assert.equal(posts[0].payload.refund_id, id);
    }, failure, ({ dto }) => {
        Object.assign(dto.props.preview, { satisfied: true, canFund: false, current: { amount: '300', asset: 'USDT' } });
        Object.assign(dto.props.preview.refund, { canRequest: !cancel, pendingId: cancel ? id : null, waitDays: 7, progress: 'waiting' });
    });
    for (const matured of [false, true]) await scenario('wealth-' + (matured ? 'redeem' : 'cancel'), '/wealth/orders/' + id + (matured ? '?fixture=matured' : ''), async ({ page, posts }) => {
        const open = button(page, matured ? 'Redeem to USDT wallet' : 'Withdraw entire deposit early');
        await open.click(); assert.equal(posts.length, 0);
        await confirm(page); await waitPost(posts, 1);
        await page.locator('.modal-panel').waitFor({ state: 'hidden' });
        assert.equal(posts[0].key, '/client/wealth/orders/' + id + (matured ? '/redeem' : '/cancel'));
        if (!matured) assert.equal(posts[0].payload.expected_paid, '6.66666666');
        else assert.equal('expected_paid' in posts[0].payload, false);
        await open.click(); assert.equal(await field(page, 'Current password').inputValue(), '');
        await confirm(page); await waitPost(posts, 2);
        assert.equal(posts[0].payload.request_id, posts[1].payload.request_id);
    });
    const promotionReady = ({ dto }) => {
        Object.assign(dto.props.paid.paymentAccess, { verified: true, walletActive: true });
        dto.props.paid.availableBalance = '2000';
        dto.props.quote = { id, rank: 1, amount: '700.00000000', depositApplied: '300.00000000', settlementTotal: '1000.00000000', previousTariff: '0', tariff: '1000', status: 'QUOTED', expiresAt: '2099-01-01T00:00:00Z', cycleId: null };
    };
    await scenario('promotion-payment-retry', '/promotion/membership', async ({ page, posts }) => {
        await page.getByText('700.00 USDT', { exact: true }).waitFor();
        await button(page, 'Confirm promotion payment').click();
        assert.equal(posts.length, 0);
        await confirm(page); await waitPost(posts, 1);
        await page.waitForFunction(() => [...document.querySelectorAll('input')].every(i => i.value !== 'synthetic-only'));
        await confirm(page); await waitPost(posts, 2);
        assert.equal(posts[0].key, '/client/promotion/quotes/' + id + '/confirm');
        assert.deepEqual(posts[0].payload, posts[1].payload);
        assert.equal('amount' in posts[0].payload, false);
    }, failure, promotionReady);
    await scenario('promotion-expired-quote', '/promotion/membership', async ({ page, posts }) => {
        await page.getByText('The payment quote has expired. Review fees again.', { exact: true }).waitFor();
        assert.notEqual(await button(page, 'Confirm promotion payment').getAttribute('disabled'), null);
        assert.equal(posts.length, 0);
    }, failure, state => { promotionReady(state); state.dto.props.quote.expiresAt = '2020-01-01T00:00:00Z'; });
    await scenario('support-image-upload-retry', '/support', async ({ page, posts }) => {
        const picker = page.waitForEvent('filechooser');
        await page.getByLabel('Attach image', { exact: true }).click();
        await (await picker).setFiles({ name: 'synthetic.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aO7sAAAAASUVORK5CYII=', 'base64') });
        await page.locator('.support-preview').waitFor();
        await button(page, 'Send message').click(); await waitPost(posts, 1);
        await page.getByText('Message not confirmed. Your draft is kept; please retry.', { exact: true }).waitFor();
        await button(page, 'Send message').click(); await waitPost(posts, 2);
        for (const post of posts) {
            assert.equal(post.key, '/support/messages');
            assert.match(post.payload.multipart, /name="support_image"/);
        }
        const requestId = p => p.multipart.match(/name="request_id"\r\n\r\n([^\r]+)/)?.[1];
        assert.ok(requestId(posts[0].payload));
        assert.equal(requestId(posts[0].payload), requestId(posts[1].payload));
    });

    for (const kind of ['jpeg', 'webp', 'gif', 'oversize']) await scenario('support-image-' + kind, '/support', async ({ page, posts }) => {
        let buffer, mimeType = 'image/' + kind;
        if (kind === 'jpeg') buffer = readFileSync('public/images/marketing/growth/poster-770.jpg');
        else if (kind === 'webp') buffer = Buffer.from(await page.evaluate(() => {
            const canvas = document.createElement('canvas'); canvas.width = canvas.height = 4;
            return canvas.toDataURL('image/webp').split(',')[1];
        }), 'base64');
        else if (kind === 'gif') buffer = Buffer.from('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', 'base64');
        else { buffer = Buffer.alloc(5 * 1024 * 1024 + 1); mimeType = 'image/png'; }
        const picker = page.waitForEvent('filechooser');
        await page.getByLabel('Attach image', { exact: true }).click();
        await (await picker).setFiles({ name: 'synthetic.' + kind, mimeType, buffer });
        if (kind === 'jpeg' || kind === 'webp') await page.locator('.support-preview').waitFor();
        else {
            await page.getByText('Use a JPG, PNG or WebP image up to 5 MB and 20 megapixels.', {exact:true}).waitFor();
            assert.equal(await page.locator('.support-preview').count(), 0);
        }
        assert.equal(posts.length, 0);
    });
    for (const [name, path] of [['assets','/dashboard'], ['cards','/cards'], ['account','/account']]) await scenario('home-header-' + name, path, async ({ page }) => {
        await page.locator('.brand-header').waitFor();
        assert.equal(await page.locator('.brand-header img[src*=bell]').count(), 0);
        if (name === 'assets') assert.equal(await page.getByText('Recent requests', {exact:true}).count(), 0);
    });
    await scenario('messages-header', '/messages', async ({ page }) => {
        await page.getByText('No messages yet', {exact:true}).waitFor();
        assert.equal(await page.locator('.page-header img[src*=bell]').count(), 0);
        assert.equal(await page.getByText('Mark all read', {exact:true}).count(), 0);
    });
    await scenario('invitation-table-zh', '/promotion/invitations', async ({ page }) => {
        const headings = await page.locator('.summary .table-head uni-text').evaluateAll(es => es.map(e => e.innerText));
        assert.deepEqual(headings.slice(1), ['直推\n人数','间推\n人数','年费\n佣金','激活\n佣金']);
        await page.locator('.summary .table-row uni-button').first().click();
        await page.locator('.summary .detail').waitFor();
        const row = await page.locator('.summary .table-row uni-button').first().boundingBox();
        const label = await page.locator('.summary .level-label').first().boundingBox();
        assert.ok(label.x >= row.x && label.x + label.width <= row.x + row.width + 1);
    }, failure, () => {}, 'zh-CN');
    await scenario('report-filter-zh', '/promotion/daily', async ({ page }) => {
        await button(page, '筛选').click();
        const text = await page.locator('.modal-panel').innerText();
        assert.doesNotMatch(text, /Filter|Reset|Apply/);
        assert.match(text, /重置/); assert.match(text, /确定/);
        await page.locator('.modal-header uni-button').click();
    }, failure, () => {}, 'zh-CN');
    await scenario('guide-sticky-header', '/promotion/reward-guide', async ({ page }) => {
        await page.locator('.sticky-header').waitFor();
        await page.mouse.wheel(0, 1400); await page.waitForTimeout(350);
        const header = await page.locator('.sticky-header').boundingBox();
        assert.ok(header.y >= -1 && header.y < 5, JSON.stringify(header));
        await page.locator('.toc-link').last().click(); await page.waitForTimeout(500);
        const position = await page.locator('.reader-section').last().boundingBox();
        assert.ok(position.y >= header.height - 1, JSON.stringify(position));
    });
    for (const locale of ['zh-CN', 'en', 'ms', 'es']) for (const width of [375,768,1440]) {
        await scenario('poster-' + locale + '-' + width, '/promotion', async ({ page }) => {
            await page.locator('.share-buttons uni-button').first().click();
            const preview = page.locator('.modal-panel .preview img');
            await preview.waitFor();
            await page.waitForTimeout(250);
            const panel = await page.locator('.modal-panel').boundingBox();
            assert.ok(panel.x >= 0 && panel.y >= 0 && panel.x + panel.width <= width + 1 && panel.y + panel.height <= 901, JSON.stringify(panel));
            const hasQr = await preview.evaluate(img => {
                const canvas = document.createElement('canvas'); canvas.width = img.naturalWidth; canvas.height = img.naturalHeight;
                const ctx = canvas.getContext('2d'); ctx.drawImage(img, 0, 0);
                const bytes = ctx.getImageData(340, canvas.height - 330, 220, 220).data;
                let black=0,white=0; for(let i=0;i<bytes.length;i+=4){if(bytes[i]<10&&bytes[i+1]<10&&bytes[i+2]<10)black++;if(bytes[i]>245&&bytes[i+1]>245&&bytes[i+2]>245)white++;}
                return {black,white};
            });
            assert.ok(hasQr.black > 1000 && hasQr.white > 1000, JSON.stringify(hasQr));
            const download = page.waitForEvent('download');
            await page.locator('.modal-panel uni-button.primary').click();
            assert.match((await download).suggestedFilename(), /^invitation-.*\.png$/);
            await page.screenshot({ path: out + '/poster-' + locale + '-' + width + '-open.png' });
            await page.locator('.modal-header uni-button').click();
            await page.locator('.modal-panel').waitFor({ state:'hidden' });
        }, failure, ({ dto }) => { dto.props.home.posterBackground = width === 375 ? '/fixture-background.jpg' : null; }, locale, width);
    }
} finally { await browser.close(); }
