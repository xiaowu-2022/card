import assert from 'node:assert/strict';
import { mkdir } from 'node:fs/promises';
import { chromium } from 'playwright';

// Uses existing local accounts. Real article persistence/isolation is covered in
// TenantArticlesTest against card_ui_test. Never saves example policies to live data.
const browser = await chromium.launch({ channel: 'chrome', headless: true });
const output = '/tmp/card-tenant-articles-browser';
await mkdir(output, { recursive: true });
const base = 'http://a.localhost:8000';
const errors = [];
let checks = 0;
async function pageFor(context) {
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.route('**/*', route => {
        const request = route.request();
        const path = new URL(request.url()).pathname;
        if (!['GET', 'HEAD', 'OPTIONS'].includes(request.method()) && !['/login', '/admin/login', '/admin/locale'].includes(path)) {
            errors.push(`Blocked unexpected write: ${path}`);
            return route.abort();
        }
        return route.continue();
    });
    return page;
}
async function inspect(page, name) {
    for (const width of [375, 768, 1440]) {
        await page.setViewportSize({ width, height: 1000 });
        assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), `${name} overflow at ${width}`);
        await page.screenshot({ path: `${output}/${name}-${width}.png`, fullPage: true });
        checks++;
    }
}
async function select(page, id, option) {
    await page.locator(`#${id}`).click();
    await page.getByRole('option', { name: option, exact: true }).click();
}
try {
    const adminContext = await browser.newContext({ locale: 'zh-CN' });
    const admin = await pageFor(adminContext);
    await admin.goto(`${base}/admin/login`);
    await admin.getByLabel('工作邮箱').fill('owner@a.localhost');
    await admin.getByLabel('密码', { exact: true }).fill('123456');
    await admin.getByRole('button', { name: '登录', exact: true }).click();
    await admin.waitForURL(/\/admin\/(onboarding|demo)$/);
    await admin.goto(`${base}/admin/settings/branding`);
    await admin.getByRole('link', { name: '关于我们文章', exact: true }).click();
    await admin.waitForURL('**/admin/settings/articles');
    await admin.locator('#article-body-terms-zh-CN').fill('未保存的服务条款草稿');
    await select(admin, 'article-kind', '隐私政策');
    await admin.locator('#article-body-privacy-zh-CN').fill('未保存的隐私政策草稿');
    await select(admin, 'article-kind', '服务条款');
    assert.equal(await admin.locator('#article-body-terms-zh-CN').inputValue(), '未保存的服务条款草稿');
    await select(admin, 'article-language', '英语');
    await admin.locator('#article-body-terms-en').fill('Unsaved English terms');
    await select(admin, 'article-language', '简体中文');
    assert.equal(await admin.locator('#article-body-terms-zh-CN').inputValue(), '未保存的服务条款草稿');
    await select(admin, 'article-kind', '注销账户');
    await admin.getByText('此处仅设置注销账户的说明文章，保存不会注销账户或处理资金。', { exact: true }).waitFor();
    await inspect(admin, 'admin-articles-zh-CN');
    await admin.getByRole('button', { name: '语言', exact: true }).click();
    await admin.getByRole('menuitem', { name: 'English' }).click();
    await admin.waitForFunction(() => document.documentElement.lang === 'en');
    await select(admin, 'article-kind', 'Terms of service');
    assert.equal(await admin.locator('#article-body-terms-zh-CN').inputValue(), '未保存的服务条款草稿');
    await inspect(admin, 'admin-articles-en');
    await adminContext.close();

    const userContext = await browser.newContext({ locale: 'zh-CN' });
    const user = await pageFor(userContext);
    await user.goto(`${base}/login`);
    await user.locator('input[type=email]').fill('user@a.localhost');
    await user.locator('input[type=password]').fill('123456');
    await user.locator('button[type=submit]').click();
    await user.waitForURL('**/dashboard');
    await user.goto(`${base}/account`);
    await user.locator('a[href="/about"]').click();
    await user.waitForURL('**/about');
    assert.equal(await user.locator('.user-about-link').count(), 3);
    assert.equal(await user.locator('.user-header, .user-bottom-nav').count(), 0);
    await inspect(user, 'about');
    for (const key of ['terms', 'privacy', 'account-closure']) {
        await user.locator(`a[href="/about/${key}"]`).click();
        await user.waitForURL(`**/about/${key}`);
        await user.locator('main [role=status], main article').waitFor();
        await inspect(user, key);
        await user.locator('a[href="/about"]').click();
        await user.waitForURL('**/about');
    }
    // Browser-only response fixture proves escaping and wrapping; never persisted.
    const fixture = `示例正文\n\n<script>window.articleScriptExecuted=true</script>\n${'very-long-word'.repeat(70)}`;
    await user.route('**/about/terms', async route => {
        if (route.request().headers()['x-inertia'] !== 'true') return route.continue();
        const response = await route.fetch();
        const data = await response.json();
        data.props.article.body = fixture;
        await route.fulfill({ response, json: data });
    });
    await user.locator('a[href="/about/terms"]').click();
    await user.locator('main article').waitFor();
    assert.equal(await user.locator('main article').textContent(), fixture);
    assert.equal(await user.locator('main article script').count(), 0);
    assert.equal(await user.evaluate(() => window.articleScriptExecuted), undefined);
    await inspect(user, 'article-escaped-text');
    await userContext.close();
    assert.deepEqual(errors, []);
    console.log(JSON.stringify({ responsiveChecks: checks, draftsPreserved: true, textEscaped: true, screenshots: output }));
} finally {
    await browser.close();
}
