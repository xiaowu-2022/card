// Run only against the dedicated card_ui_test acceptance server on port 8010.
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
const browser = await chromium.launch({channel:'chrome',headless:true});
const page = await browser.newPage();
try {
await page.goto('http://admin.localhost:8010/platform/login');
await page.locator('input[type=email]').fill('owner@platform.local');
await page.locator('input[type=password]').fill('local-password');
await page.locator('form button:not([type=button])').click();
await page.waitForURL('**/platform/tenants');
await page.goto('http://admin.localhost:8010/platform/settings/assets');
const section=page.locator('section').filter({has:page.getByRole('heading',{name:'USDT · ERC20',exact:true})});
const input=section.locator('input:not([type=checkbox])');
const address = await input.inputValue() === '0x2222222222222222222222222222222222222222'
    ? '0x1111111111111111111111111111111111111111'
    : '0x2222222222222222222222222222222222222222';
await input.fill('invalid-address');
await section.locator('input[type=checkbox]').check();
await page.locator('form button[type=submit]').click();
await page.waitForTimeout(1500);
assert.equal(await input.inputValue(), 'invalid-address');
assert.match(await page.locator('main').innerText(), /本次所有修改均未保存|No changes were saved/);
assert.ok((await section.locator('[role=alert]').count()) > 0);
await input.fill(address);
await page.locator('form button[type=submit]').click();
await page.waitForTimeout(1000);
await page.reload();
assert.equal(await input.inputValue(), address);
const btc = page.locator('section').filter({has:page.getByRole('heading',{name:'BTC · Bitcoin',exact:true})});
await btc.locator('input:not([type=checkbox])').fill('1ABTsAkgEURNKruU8T7F7edkeqwTrgBpec');
await btc.locator('input[type=checkbox]').check();
await page.locator('form button[type=submit]').click();
await page.waitForTimeout(1000);
await page.reload();
assert.equal(await btc.locator('input:not([type=checkbox])').inputValue(), '1ABTsAkgEURNKruU8T7F7edkeqwTrgBpec');
assert.equal(await btc.locator('input[type=checkbox]').isChecked(), true);
const tron = page.locator('section').filter({has:page.getByRole('heading',{name:'USDT · TRON (TRC20)',exact:true})}).locator('input:not([type=checkbox])');
const tronAddress = await tron.inputValue() === 'T' + '3'.repeat(33) ? 'T' + '4'.repeat(33) : 'T' + '3'.repeat(33);
await tron.fill(tronAddress);
await page.locator('form button[type=submit]').click();
await page.waitForTimeout(1000);
await page.reload();
assert.equal(await tron.inputValue(), tronAddress);
console.log('PASS: failure preserves input and explains atomic rollback; corrected save survives reload.');
} finally {await browser.close();}
