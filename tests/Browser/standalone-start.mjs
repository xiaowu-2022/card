import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { chromium } from 'playwright';

const source = readFileSync('public/start.html', 'utf8');
const browser = await chromium.launch({ channel: 'chrome', headless: true });
async function scenario({ domains, failed = false, empty = false }) {
  const context = await browser.newContext({ viewport: { width: 375, height: 812 } });
  const page = await context.newPage();
  const errors = [];
  const probes = [];
  page.on('pageerror', error => errors.push(error.message));
  let html = source;
  if (domains) html = html.replace(/domains: \[[\s\S]*?\],/, 'domains: ' + JSON.stringify(domains) + ',');
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.host === 'launcher.test') return route.fulfill({ contentType: 'text/html', body: html });
    if (url.searchParams.has('_route_probe')) {
      probes.push(url);
      if (failed) return route.abort();
      await new Promise(resolve => setTimeout(resolve, url.host === '113d.my' ? 10 : 350));
      return route.fulfill({ status: 200, body: 'OK' }).catch(() => {});
    }
    return route.fulfill({ contentType: 'text/html', body: '<title>Destination</title>' });
  });
  await page.goto('https://launcher.test/start.html?invite=123&x=a%2Bb&x=%E4%B8%AD%E6%96%87#/register?step=2');
  if (empty) {
    await page.getByText('等待配置线路', { exact: true }).waitFor();
    assert.equal(probes.length, 0);
  } else if (failed) {
    await page.getByRole('button', { name: '重试' }).waitFor();
    assert.match(await page.locator('#routes a').first().getAttribute('href'), /invite=123/);
    await page.getByRole('button', { name: '重试' }).click();
    await page.getByRole('button', { name: '重试' }).waitFor();
    assert.equal(probes.length, 4);
  } else {
    await page.waitForURL('https://113d.my/**');
    assert.equal(page.url(), 'https://113d.my/' + (domains ? 'h5/?base=1&' : '?') + 'invite=123&x=a%2Bb&x=%E4%B8%AD%E6%96%87#/register?step=2');
    assert.ok(probes.length >= 2);
    assert.ok(probes.every(url => !url.searchParams.has('invite') && !url.hash));
  }
  assert.deepEqual(errors, []);
  await context.close();
}
try {
  await scenario({});
  await scenario({ domains: ['https://113b.my/', 'https://113d.my/h5/?base=1#default'] });
  await scenario({ domains: ['https://113b.my/', 'https://113c.my/'], failed: true });
  await scenario({ domains: [], empty: true });
  console.log('PASS: default 23 domains, fastest route, raw query/hash, base parameters, failure/retry, empty configuration (offline Chrome).');
} finally {
  await browser.close();
}
