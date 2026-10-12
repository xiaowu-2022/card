import assert from 'node:assert/strict';
import { readFileSync, mkdirSync } from 'node:fs';
import { chromium, webkit } from 'playwright';
const origin = 'http://admin.localhost:8000', company = '11111111-1111-4111-8111-111111111111';
const entry = JSON.parse(readFileSync('public/build/manifest.json'))['resources/js/app.tsx'];
mkdirSync('artifacts/admin-pagination', { recursive: true });
const urlFor = (page) => `/platform/cards?company=${company}&search=fixture&tab=cards&orders_page=7&cards_page=${page}`;
for (const [name, engine] of [['chrome', chromium], ['webkit', webkit]]) {
 const browser = await engine.launch(name === 'chrome' ? { channel: 'chrome' } : {});
 try {
  const page = await browser.newPage(); const errors = [], unexpected = []; let locale = 'zh-CN';
  page.on('pageerror', (error) => errors.push(error.message));
  const empty = { data: [], total: 0, current_page: 1, last_page: 1, prev_page_url: null, next_page_url: null };
  const state = (current) => ({ component: 'platform/Cards', url: urlFor(current), version: 'pagination-fixture', props: { errors: {}, publicAssets: [], tenant: null, flash: {}, auth: { admin: { name: 'Offline administrator', permissions: [] }, user: null }, i18n: { locale, enabledLocales: ['zh-CN', 'en'], timezone: 'UTC', surface: 'platform' }, orders: empty, loads: empty, cards: { data: [], total: 1000, current_page: current, last_page: 50, first_page_url: origin + urlFor(1), prev_page_url: current > 1 ? origin + urlFor(current - 1) : null, next_page_url: current < 50 ? origin + urlFor(current + 1) : null }, companies: [{ id: company, name: 'Offline company' }], filters: { company, search: 'fixture', tab: 'cards' }, canVoidCards: false } });
  await page.route('**/*', async (route) => {
   const request = route.request(), url = new URL(request.url());
   if (url.pathname.startsWith('/build/') && request.method() === 'GET') return route.fulfill({ body: readFileSync('public' + url.pathname), contentType: url.pathname.endsWith('.css') ? 'text/css' : 'text/javascript' });
   if (request.method() !== 'GET' || url.pathname !== '/platform/cards') { unexpected.push(`${request.method()} ${url.pathname}`); return route.abort(); }
   const current = Number(url.searchParams.get('cards_page') || 1);
   assert.equal(url.searchParams.get('company'), company); assert.equal(url.searchParams.get('search'), 'fixture'); assert.equal(url.searchParams.get('tab'), 'cards'); assert.equal(url.searchParams.get('orders_page'), '7');
   if (request.headers()['x-inertia']) return route.fulfill({ headers: { 'X-Inertia': 'true' }, json: state(current) });
   return route.fulfill({ contentType: 'text/html', body: `<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">${(entry.css ?? []).map((file) => `<link rel="stylesheet" href="/build/${file}">`).join('')}</head><body><script data-page="app" type="application/json">${JSON.stringify(state(current)).replaceAll('<','\\u003c')}</script><div id="app"></div><script type="module" src="/build/${entry.file}"></script></body></html>` });
  });
  for (locale of ['zh-CN','en']) for (const width of [375,768,1440]) {
   await page.setViewportSize({ width, height: 900 }); await page.goto(origin + urlFor(1));
   const nav = page.locator('main nav').last(); await nav.getByRole('button', { name: '1', exact: true }).waitFor();
   assert.equal(await nav.getByRole('button', { name: '1', exact: true }).getAttribute('aria-current'), 'page');
   assert.equal(await nav.getByRole('button', { name: locale === 'zh-CN' ? '上一页' : 'Previous', exact: true }).isEnabled(), false);
   await nav.getByRole('link', { name: '2', exact: true }).click(); await nav.getByRole('button', { name: '2', exact: true }).waitFor();
   assert.equal(new URL(page.url()).searchParams.get('cards_page'), '2');
   await nav.getByRole('spinbutton', { name: locale === 'zh-CN' ? '跳转页码' : 'Go to page', exact: true }).fill('20');
   await nav.getByRole('button', { name: locale === 'zh-CN' ? '跳转' : 'Go', exact: true }).click(); await nav.getByRole('button', { name: '20', exact: true }).waitFor();
   assert.equal(await nav.getByRole('link', { name: '1', exact: true }).count(), 1); assert.equal(await nav.getByRole('link', { name: '50', exact: true }).count(), 1);
   assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
   if (locale === 'zh-CN') await page.screenshot({ path: `artifacts/admin-pagination/${name}-${width}.png` });
   await nav.getByRole('link', { name: '50', exact: true }).click(); await nav.getByRole('button', { name: '50', exact: true }).waitFor();
   assert.equal(await nav.getByRole('button', { name: locale === 'zh-CN' ? '下一页' : 'Next', exact: true }).isEnabled(), false);
  }
  assert.deepEqual(errors, []); assert.deepEqual(unexpected, []);
  console.log(`PASS ${name}: numbered pages, active/disabled boundaries, direct jump, ellipses, named page/filter preservation, two languages and three widths; offline GETs only.`);
 } finally { await browser.close(); }
}
