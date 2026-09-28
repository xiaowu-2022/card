// Offline browser contract checks; real Laravel auth flows are tested separately in Pest.
import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium } from 'playwright';
import { addParityStates } from '../../scripts/client/parity-states.mjs';
const fixture = addParityStates(
    JSON.parse(readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8')),
);
const origin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5202';
const id = '11111111-1111-4111-8111-111111111111';
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const out = 'artifacts/uni-parity/auth-acceptance';
mkdirSync(out, { recursive: true });
const field = (p, label) =>
    p
        .locator('.form-field')
        .filter({
            has: p.locator('.form-label').filter({ hasText: new RegExp('^' + label + '$') }),
        })
        .locator('input');
async function check(name, path, run, post, language = 'en', alter = () => {}) {
    const context = await browser.newContext({ viewport: { width: 375, height: 900 } }),
        page = await context.newPage();
    page.setDefaultTimeout(8000);
    const state = {
        boot: {
            ...structuredClone(fixture.guest),
            locale: language,
            locales: ['en', 'zh-CN', 'ms', 'es'],
        },
        verified: false,
        expired: false,
        posts: [],
        reads: [],
        errors: [],
    };
    state.boot.tenant.primaryColor = '#6040a0';
    alter(state);
    page.on('pageerror', (e) => state.errors.push(e.message));
    await context.route('**/*', async (route) => {
        const req = route.request(),
            u = new URL(req.url()),
            key = u.pathname.replace('/api/v1', '');
        if (u.pathname.startsWith('/api/v1')) {
            if (req.method() !== 'GET') {
                const body = req.postDataJSON();
                state.posts.push({ key, body });
                return route.fulfill((await post(key, body, state)) ?? { json: { success: true } });
            }
            state.reads.push(key);
            if (key === '/bootstrap') return route.fulfill({ json: state.boot });
            if (key === '/unread') return route.fulfill({ json: { messages: 0, support: 0 } });
            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
            let source = key.replace(/^\/client/, '');
            if (source === '/register/challenges/' + id)
                source += '?fixture=' + (state.verified ? 'VERIFIED' : 'PENDING');
            let dto = structuredClone(fixture.pages[source]);
            assert.ok(dto, 'Missing fixture ' + source);
            dto.props.i18n.locale = state.boot.locale;
            if (source === '/register') {
                dto.props.registration.invitationCode = '012345';
                dto.props.registration.invitationLocked = true;
            }
            if (state.expired && dto.props.challenge) dto.props.challenge.status = 'EXPIRED';
            return route.fulfill({ json: dto });
        }
        if (req.method() !== 'GET' || u.origin !== origin) return route.abort();
        return route.continue();
    });
    try {
        await page.goto(
            origin +
                (path === '/login'
                    ? '/#/pages/login/index'
                    : '/#/pages/screen/index?path=' + encodeURIComponent(path)),
        );
        await page.locator('.auth-root').waitFor();
        await run(page, state);
        assert.deepEqual(state.errors, []);
        assert.equal(
            await page.evaluate(() => document.documentElement.scrollWidth > innerWidth),
            false,
        );
        assert.equal(
            await page.evaluate(() => JSON.stringify(localStorage).includes('SyntheticPass')),
            false,
        );
        await page.screenshot({ path: out + '/' + name + '.png', fullPage: true });
        console.log('PASS ' + name);
    } catch (e) {
        await page.screenshot({ path: out + '/' + name + '-failed.png', fullPage: true });
        throw e;
    } finally {
        await context.close();
    }
}
try {
    for (const language of ['en', 'zh-CN', 'ms', 'es'])
        await check(
            'login-errors-' + language,
            '/login',
            async (p, s) => {
                assert.notEqual(await p.locator('.auth-submit').getAttribute('disabled'), null);
                await p.locator('.login-input input').nth(0).fill('offline@example.test');
                await p.locator('.password-field input').fill('SyntheticPass123');
                await p.locator('.password-toggle').click();
                assert.equal(await p.locator('.password-field input').getAttribute('type'), 'text');
                await p.locator('.auth-submit').click();
                await p.locator('.form-errors').waitFor();
                assert.equal(s.posts.length, 1);
                assert.equal(s.posts[0].body.identifier, 'offline@example.test');
                assert.equal(s.posts[0].body.password, 'SyntheticPass123');
                assert.ok((await p.locator('.form-errors').innerText()).length > 5);
                await p.locator('.forgot-link').click();
                await p.locator('.recovery-button').waitFor();
                assert.equal(
                    await p
                        .locator('.password-field input')
                        .evaluateAll((inputs) => inputs.every((input) => input.value === '')),
                    true,
                );
            },
            () => ({ status: 422, json: { errors: { identifier: ['Invalid credentials.'] } } }),
            language,
        );
    await check(
        'registration-code-retry',
        '/register',
        async (p, s) => {
            assert.equal(await field(p, 'Invitation code').inputValue(), '012345');
            assert.equal(await field(p, 'Invitation code').isDisabled(), true);
            await field(p, 'Email address').fill('new@example.test');
            await p.locator('.auth-button').click();
            await p.locator('.auth-button').click({ force: true });
            await field(p, 'Verification code').waitFor();
            assert.equal(s.posts.filter((x) => x.key === '/client/register/challenges').length, 1);
            assert.equal(s.posts[0].body.invitation_code, '012345');
            await field(p, 'Verification code').fill('000001');
            await p.locator('.auth-button').click();
            await p.locator('.form-errors').waitFor();
            await p.waitForFunction(
                () =>
                    document.querySelector('uni-input[aria-label="Verification code"] input')
                        ?.value === '',
            );
            await field(p, 'Verification code').fill('012345');
            await p.locator('.auth-button').click();
            await field(p, 'Password').waitFor();
            await field(p, 'Password').fill('SyntheticPass123');
            await field(p, 'Confirm password').fill('different');
            await p.locator('.auth-button').click();
            await p.locator('.form-errors').waitFor();
            assert.equal(s.posts.at(-1).body.locale, 'en');
            assert.equal(s.posts.at(-2).body.code, '012345');
            await p.locator('.auth-back').click();
            await p.locator('.auth-submit').waitFor();
            assert.equal(
                await p
                    .locator('.password-field input')
                    .evaluateAll((inputs) => inputs.every((input) => input.value === '')),
                true,
            );
        },
        async (key, body, s) => {
            if (key === '/client/register/challenges') {
                await new Promise((r) => setTimeout(r, 250));
                return { json: { redirect: '/register/challenges/' + id } };
            }
            if (key.endsWith('/verify')) {
                if (body.code === '012345') {
                    s.verified = true;
                    return { json: { redirect: '/register/challenges/' + id } };
                }
                return {
                    status: 422,
                    json: {
                        errors: { code: ['This verification request is invalid or expired.'] },
                    },
                };
            }
            return {
                status: 422,
                json: { errors: { password: ['The password field confirmation does not match.'] } },
            };
        },
    );
    await check(
        'expired-challenge',
        '/register/challenges/' + id,
        async (p, s) => {
            await p.getByText('Challenge unavailable', { exact: true }).waitFor();
            assert.equal(await p.locator('.auth-button').count(), 0);
            assert.equal(s.posts.length, 0);
        },
        () => {
            throw new Error('Expired challenge submitted');
        },
        'en',
        (s) => {
            s.expired = true;
        },
    );
    await check(
        'recovery-confirmation',
        '/forgot-password/' + id,
        async (p, s) => {
            assert.notEqual(await p.locator('.recovery-button').getAttribute('disabled'), null);
            await field(p, 'Verification code').fill('012345');
            await field(p, 'New password').fill('SyntheticPass123');
            await field(p, 'Confirm new password').fill('SyntheticPass123');
            await p.locator('.confirmation').click();
            await p.locator('.recovery-button').click();
            await p.locator('.auth-submit').waitFor();
            assert.equal(s.posts.length, 1);
            assert.equal(s.posts[0].body.confirmed, true);
            assert.equal(s.posts[0].body.code, '012345');
            assert.equal(
                await p
                    .locator('.password-field input')
                    .evaluateAll((inputs) => inputs.every((input) => input.value === '')),
                true,
            );
        },
        () => ({
            json: {
                redirect: '/login',
                success: 'Password reset. Sign in with your new password.',
            },
        }),
    );
    await check(
        'recovery-retry-intent',
        '/forgot-password',
        async (p, s) => {
            await field(p, 'Email address').fill('offline@example.test');
            await p.locator('.recovery-button').click();
            await p.locator('.form-errors').waitFor();
            await p.locator('.recovery-button').click();
            await p.waitForFunction(() => !document.querySelector('.recovery-button[disabled]'));
            assert.equal(s.posts.length, 2);
            assert.equal(s.posts[0].body.request_id, s.posts[1].body.request_id);
            await field(p, 'Email address').fill('second@example.test');
            await p.locator('.recovery-button').click();
            await p.waitForFunction(() => !document.querySelector('.recovery-button[disabled]'));
            assert.notEqual(s.posts[1].body.request_id, s.posts[2].body.request_id);
            assert.equal(
                await p
                    .locator('.recovery-button')
                    .evaluate((e) => getComputedStyle(e).backgroundColor),
                'rgb(96, 64, 160)',
            );
        },
        () => ({ status: 503, json: { error: { message: 'Unable to load. Please try again.' } } }),
    );
    await check(
        'locale-preserves-form',
        '/login',
        async (p, s) => {
            await p.locator('.login-input input').nth(0).fill('offline@example.test');
            await p.locator('.password-field input').fill('SyntheticPass123');
            const cancelLabels = ['Cancel', '取消', 'Batal', 'Cancelar'];
            let selected = 0;
            for (const label of ['简体中文', 'Bahasa Melayu', 'Español', 'English']) {
                await p.locator('.language-picker').click();
                await p.locator('.uni-picker-action-confirm').waitFor();
                assert.equal(
                    await p.locator('.uni-picker-action-cancel').textContent(),
                    cancelLabels[selected],
                );
                await p.locator('uni-picker-view-column').hover();
                for (let step = 0; step < (selected === 3 ? 3 : 1); step++) {
                    await p.mouse.wheel(0, selected === 3 ? -30 : 30);
                    await p.waitForTimeout(150);
                }
                selected++;
                await p.locator('.uni-picker-action-confirm').click();
                await p.locator('.language-picker').getByText(label, { exact: true }).waitFor();
                assert.equal(
                    await p.locator('.password-field input').inputValue(),
                    'SyntheticPass123',
                );
            }
            assert.deepEqual(
                s.posts.map((x) => x.body.locale),
                ['zh-CN', 'ms', 'es', 'en'],
            );
        },
        (key, body, s) => {
            assert.equal(key, '/client/locale');
            s.boot.locale = body.locale;
            return { json: { locale: body.locale } };
        },
    );
} finally {
    await browser.close();
}
