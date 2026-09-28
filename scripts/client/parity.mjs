// Compare generated uni H5 with the original React renderer using isolated Pest DTOs.
// Run ConsumerParityFixtureTest with UNI_PARITY_EXPORT=1 first. No business writes.
import { addParityStates } from './parity-states.mjs';
import { chromium } from 'playwright';
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { resolve } from 'node:path';
const fixture = JSON.parse(
    readFileSync('storage/framework/testing/uni-parity/fixtures.json', 'utf8'),
);
addParityStates(fixture);
const oldOrigin = 'http://127.0.0.1:8000',
    newOrigin = process.env.UNI_PARITY_ORIGIN ?? 'http://127.0.0.1:5202';
const widths = (process.env.UNI_PARITY_WIDTHS ?? '375,768,1440').split(',').map(Number);
const languages = (process.env.UNI_PARITY_LANGUAGES ?? 'zh-CN,en,ms,es').split(',');
const out = resolve('artifacts/uni-parity');
mkdirSync(out, { recursive: true });
const direct = {
    '/login': '/pages/login/index',
    '/dashboard': '/pages/assets/index',
    '/account': '/pages/account/index',
    '/cards': '/pages/cards/index',
    '/messages': '/pages/messages/index',
    '/support': '/pages/support/index',
};
const esc = (s) =>
    String(s)
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;')
        .replaceAll('"', '&quot;');
const manifest = JSON.parse(readFileSync('public/build/manifest.json', 'utf8'));
const entry = manifest['resources/js/app.tsx'];
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="offline-fixture">${entry.css.map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}<script type="module" src="/build/${entry.file}"></script></head><body class="antialiased"><script data-page="app" type="application/json">{}</script><div id="app"></div></body></html>`;
const browser = await chromium.launch({ channel: 'chrome', headless: true });
// Explicit resume keeps successful captures from this acceptance run; failed or
// incomplete pairs are always repeated. Start without this flag after UI changes.
const results = process.env.UNI_PARITY_RESUME === '1' && existsSync(resolve(out, 'results.json'))
    ? JSON.parse(readFileSync(resolve(out, 'results.json'), 'utf8')).filter(
        (pair) => pair.pages.length === 2 && pair.pages.every((page) => !page.errors.length && !page.overflow),
    ) : [];
const completed = new Set(results.map((pair) => JSON.stringify([pair.path, pair.width, pair.language])));
try {
    const tasks = widths.flatMap((width) =>
        languages.flatMap((language) =>
            Object.entries(fixture.pages)
                .filter(
                    ([path, original]) =>
                        !original.redirect &&
                        !completed.has(JSON.stringify([path, width, language])) &&
                        (!process.env.UNI_PARITY_PATHS ||
                            process.env.UNI_PARITY_PATHS.split(',').includes(path)),
                )
                .map(([path, original]) => ({ width, language, path, original })),
        ),
    );
    let index = 0;
    await Promise.all(
        Array.from({ length: 3 }, async () => {
            while (index < tasks.length) {
                const { width, language, path, original } = tasks[index++];
                const pair = { path, width, language, pages: [] };
                for (const [name, origin] of [
                    ['original', oldOrigin],
                    ['uni', newOrigin],
                ]) {
                    const context = await browser.newContext({
                        permissions: ['local-network-access'],
                        viewport: { width, height: 900 },
                        locale: language,
                    });
                    const page = await context.newPage();
                    let errors = [],
                        blocked = [];
                    page.on('pageerror', (error) => errors.push(error.message));
                    page.on('response', (response) => {
                        if (response.status() >= 400)
                            errors.push(`HTTP ${response.status()}: ${response.url()}`);
                    });
                    page.on('console', (msg) => {
                        if (msg.type() === 'error') errors.push(msg.text());
                    });
                    page.on('requestfailed', (r) =>
                        r.failure()?.errorText === 'net::ERR_ABORTED' &&
                        r.resourceType() === 'image'
                            ? undefined
                            : errors.push(r.url() + ': ' + r.failure()?.errorText),
                    );
                    const data = structuredClone(original);
                    data.props.i18n = {
                        ...data.props.i18n,
                        locale: language,
                        enabledLocales: languages,
                    };
                    data.url = path;
                    const bootstrap = structuredClone(
                        data.props.auth?.user ? fixture.authenticated : fixture.guest,
                    );
                    bootstrap.locale = language;
                    bootstrap.locales = languages;
                    bootstrap.unread = {
                        messages: data.props.unreadMessages ?? 0,
                        support: data.props.unreadSupport ?? 0,
                    };
                    await context.route('**/*', async (route) => {
                        const request = route.request(),
                            u = new URL(request.url());
                        if (!['http:', 'https:'].includes(u.protocol)) return route.continue();
                        if (request.method() !== 'GET') {
                            blocked.push(request.method() + ' ' + u.pathname);
                            return route.fulfill({ status: 200, json: { success: true } });
                        }
                        if (
                            request.isNavigationRequest() &&
                            name === 'original' &&
                            u.origin === oldOrigin
                        ) {
                            return route.fulfill({
                                contentType: 'text/html',
                                body: html.replace(
                                    /(<script data-page="app"[^>]*>)[\s\S]*?(<\/script>)/,
                                    (_, start, end) =>
                                        start +
                                        JSON.stringify(data).replaceAll('<', '\\u003c') +
                                        end,
                                ),
                            });
                        }
                        if (u.pathname.startsWith('/api/v1/')) {
                            let key = u.pathname.slice(7);
                            if (key === '/unread') return route.fulfill({ json: bootstrap.unread });
                            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
                            if (key === '/bootstrap') return route.fulfill({ json: bootstrap });
                            if (key.startsWith('/client')) {
                                const requested = key.slice(7) || '/';
                                const found =
                                    requested === path.split('?')[0]
                                        ? data
                                        : (fixture.pages[requested + u.search] ??
                                          fixture.pages[requested]);
                                if (found) {
                                    const dto = structuredClone(found);
                                    if (dto.props) dto.props.i18n = data.props.i18n;
                                    return route.fulfill({ json: dto });
                                }
                            }
                            if (key === '/unread') return route.fulfill({ json: bootstrap.unread });
                            if (fixture.api[key]) return route.fulfill({ json: fixture.api[key] });
                            errors.push('Uncovered API ' + key);
                            return route.fulfill({
                                status: 404,
                                json: { error: { message: 'Fixture not found' } },
                            });
                        }
                        if (
                            u.pathname.endsWith('/transactions') &&
                            u.pathname.startsWith('/cards/')
                        )
                            return route.fulfill({ json: { items: [], page: 1, hasMore: false } });
                        if (u.pathname === '/messages/unread-count')
                            return route.fulfill({
                                json: {
                                    count: bootstrap.unread.messages,
                                    supportCount: bootstrap.unread.support,
                                },
                            });
                        if (u.pathname === '/support' && u.search)
                            return route.fulfill({ json: fixture.pages['/support'].props.chat });
                        if (u.pathname === '/support/unread-count')
                            return route.fulfill({
                                json: {
                                    count: bootstrap.unread.messages,
                                    supportCount: bootstrap.unread.support,
                                },
                            });
                        const staticPath =
                            /\.(js|json|tsx?|css|svg|png|jpe?g|webp|woff2?|ttf|ico)(\?|$)/i.test(
                                u.pathname,
                            ) ||
                            u.pathname.startsWith('/@') ||
                            u.pathname.startsWith('/node_modules/');
                        if (
                            (request.isNavigationRequest() &&
                                name === 'uni' &&
                                u.origin === newOrigin) ||
                            (staticPath &&
                                ['127.0.0.1', 'localhost', 'a.localhost', '0.0.0.0'].includes(
                                    u.hostname,
                                ))
                        )
                            return route.continue();
                        blocked.push('GET ' + u.origin + u.pathname);
                        return route.fulfill({ status: 404, body: '' });
                    });
                    const target =
                        name === 'original'
                            ? origin + path
                            : origin +
                              '/#' +
                              (/^\/messages\/[a-f0-9-]{36}$/.test(path)
                                  ? '/pages/messages/detail?id=' + path.split('/').pop()
                                  : (direct[path.split('?')[0]] ??
                                    '/pages/screen/index?path=' + encodeURIComponent(path)));
                    await page.goto(target, { waitUntil: 'domcontentloaded' });
                    await page.waitForTimeout(550);
                    await page.evaluate(() => document.fonts.ready);
                    await page
                        .locator(name === 'uni' ? 'uni-page-body' : '#app')
                        .waitFor({ timeout: 12000 })
                        .catch((e) => {
                            console.log({ name, path, errors, blocked });
                            writeFileSync(resolve(out, 'failed.html'), html);
                            errors.push('Page did not render within 12 seconds');
                        });
                    await page.waitForTimeout(200);
                    const file = `${path === '/' ? 'landing' : path.slice(1).replace(/[^a-z0-9-]/gi, '-')}-${language}-${width}-${name}.png`;
                    await page.screenshot({ path: resolve(out, file), fullPage: true });
                    const metrics = await page.evaluate(() => ({
                        overflow: document.documentElement.scrollWidth > innerWidth,
                        height: document.documentElement.scrollHeight,
                        text: document.body.innerText,
                    }));
                    pair.pages.push({ name, file, errors, blocked, ...metrics });
                    await context.close();
                }
                results.push(pair);
                writeFileSync(resolve(out, 'results.json'), JSON.stringify(results, null, 2));
                console.log(
                    language,
                    width,
                    path,
                    pair.pages
                        .map((x) => (x.errors.length ? 'ERROR ' + x.errors.join(',') : 'OK'))
                        .join(' / '),
                );
            }
        }),
    );
} finally {
    await browser.close();
}
writeFileSync(
    resolve(out, 'index.html'),
    `<!doctype html><meta charset="utf-8"><title>uni-app H5 对照验收</title><style>body{font:15px system-ui;background:#e8eeeb;margin:24px}section{background:white;border-radius:12px;padding:18px;margin-bottom:24px}h2{font-size:18px}.pair{display:flex;gap:18px;align-items:flex-start;overflow:auto}figure{margin:0;min-width:0;flex:1}img{width:100%;border:1px solid #ddd}figcaption{margin-bottom:8px}</style><h1>原版与生成 H5 对比</h1><p>相同隔离测试 DTO。截图覆盖不代表所有业务操作已验收；逐页检查差异。</p>${results.map((p) => `<section><h2>${esc(p.path)} · ${p.language} · ${p.width}px</h2><div class="pair">${p.pages.map((x) => `<figure><figcaption>${x.name === 'original' ? '原版 React' : '生成 uni-app H5'} ${x.errors.length ? '⚠ ' + esc(x.errors.join('; ')) : ''}</figcaption><img loading="lazy" src="${x.file}"></figure>`).join('')}</div></section>`).join('')}`,
);
if (results.some((p) => p.pages.some((x) => x.errors.length || x.overflow))) process.exitCode = 1;
