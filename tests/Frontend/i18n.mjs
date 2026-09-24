import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import { createRequire } from 'node:module';
import { test } from 'node:test';
import ts from 'typescript';

const require = createRequire(import.meta.url);

test('card provider directory distinguishes manual references and configured APIs without demo providers', () => {
    const nav = readFileSync('resources/js/layouts/PlatformLayout.tsx', 'utf8');
    assert.ok(nav.includes("label: 'Card providers'"));
    assert.ok(nav.includes("href: '/platform/card-providers'"));
    assert.ok(nav.includes("permission: 'provider_operation.read'"));
    const page = readFileSync('resources/js/pages/platform/CardProviders.tsx', 'utf8');
    assert.ok(page.includes("t('No card providers')"));
    assert.match(page, /permissions\.includes\(\s*'card_provider_reference\.manage'/);
    assert.ok(page.includes('reference_balance'));
    assert.ok(page.includes('referenceBalance'));
    assert.ok(page.includes("'Manual reference'"));
    assert.ok(page.includes("'PhotonPay API · Sandbox'"));
    assert.ok(page.includes("'PhotonPay API · Production'"));
    assert.ok(page.includes("form.post('/platform/card-providers'"));
    assert.ok(!page.includes('PHOTONPAY'));
    const overview = readFileSync('resources/js/pages/platform/Dashboard.tsx', 'utf8');
    assert.ok(!overview.includes('Mock Provider EU'));
    assert.ok(!overview.includes('Mock Provider US'));
    assert.ok(!overview.includes('cardProviderCount'));
});

test('SaaS users and payment orders have independent permission-scoped navigation', () => {
    const nav = readFileSync('resources/js/layouts/PlatformLayout.tsx', 'utf8');
    for (const [path, permission] of [['/platform/users', 'users.read'], ['/platform/topups', 'wallet_topups.read']]) {
        assert.ok(nav.includes(`href: '${path}'`));
        assert.ok(nav.includes(`permission: '${permission}'`));
    }
    assert.ok(nav.includes("label: 'Payment orders'"));
    const users = readFileSync('resources/js/pages/platform/Users.tsx', 'utf8');
    assert.ok(users.includes('companies={companies}'));
    assert.ok(users.includes('row.companyName'));
    assert.ok(!users.includes('.post('));
    assert.ok(readFileSync('resources/js/pages/platform/Topups.tsx', 'utf8').includes("title={t('Payment orders')}"));
});

test('SaaS identity and wallet navigation uses real permission-scoped read pages', () => {
    const nav = readFileSync('resources/js/layouts/PlatformLayout.tsx', 'utf8');
    for (const [path, permission] of [['/platform/kyc', 'kyc.read'], ['/platform/wallets', 'wallet.read']]) {
        assert.ok(nav.includes(`href: '${path}'`));
        assert.ok(nav.includes(`permission: '${permission}'`));
    }
    const wallet = readFileSync('resources/js/pages/platform/Wallets.tsx', 'utf8');
    assert.ok(wallet.includes("permissions.includes('wallet_topups.read')"));
    assert.ok(wallet.includes('/platform/topups'));
    assert.ok(wallet.includes('companies={companies}'));
    assert.ok(wallet.includes('row.companyName'));
    assert.ok(wallet.includes('displayMoney(row.available)'));
    assert.ok(wallet.includes('displayMoney(row.securityDeposit)'));
    assert.ok(wallet.includes('displayMoney(row.held)'));
    const kyc = readFileSync('resources/js/pages/platform/Kyc.tsx', 'utf8');
    assert.ok(!kyc.includes('identity_number'));
    assert.ok(!kyc.includes('.post('));
    for (const key of ['Select a company to view its records.', 'Change company', 'Held amount', 'Top-up management', 'Account ID', 'No matching records.']) {
        assert.ok(i18n.clientResources.en.admin[key]);
        assert.ok(i18n.clientResources['zh-CN'].admin[key]);
    }
});

test('fixed withdrawal fee previews use exact decimal arithmetic and confirmed quotes', () => {
    const { withdrawalReceiveAmount } = loadTs('resources/js/lib/exact-amount.ts');
    assert.equal(withdrawalReceiveAmount('100.01', '2.50000000'), '97.51000000');
    assert.equal(withdrawalReceiveAmount('0.01', '0.00000000'), '0.01000000');
    assert.equal(withdrawalReceiveAmount('999999999999.99', '0.01'), '999999999999.98000000');
    for (const [amount, fee] of [
        ['2.50', '2.50'],
        ['2', '2.50'],
        ['0', '0'],
        ['1.001', '0'],
        ['1', '-1'],
        ['1e2', '0'],
    ]) {
        assert.equal(withdrawalReceiveAmount(amount, fee), null);
    }
    const form = readFileSync('resources/js/pages/user/Withdraw.tsx', 'utf8');
    assert.ok(form.includes('expected_fee: reviewedFee'));
    assert.ok(form.includes('setReviewedFee(calculatedFee!)'));
    assert.equal((form.match(/<WithdrawalAmounts/g) ?? []).length, 2);
    const summary = readFileSync('resources/js/components/user/WithdrawalAmounts.tsx', 'utf8');
    for (const key of [
        'Withdrawal fee',
        'Amount to receive',
        'Withdrawal amount must be greater than the fee.',
        'The withdrawal fee has changed. Review the updated fee before confirming.',
    ]) {
        assert.equal(catalog[key].length, 3);
        assert.ok(catalog[key].every((text) => text.length > 0));
    }
    assert.ok(summary.includes("t('Withdrawal fee')"));
    assert.ok(summary.includes("t('Amount to receive')"));
});

function loadTs(path, overrides = {}) {
    const source = ts.transpileModule(readFileSync(path, 'utf8').replaceAll('import.meta.env.DEV', 'false'), {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
            esModuleInterop: true,
            jsx: ts.JsxEmit.ReactJSX,
        },
    }).outputText;
    const module = { exports: {} };
    new Function('require', 'module', 'exports', source)(
        (name) => overrides[name] ?? require(name),
        module,
        module.exports,
    );
    return module.exports;
}
const promotionCatalog = loadTs('resources/js/i18n/promotion-catalog.ts');
const { catalog } = loadTs('resources/js/i18n/catalog.ts', {
    './physical-card-catalog': loadTs('resources/js/i18n/physical-card-catalog.ts'),
    './paid-promotion-catalog': loadTs('resources/js/i18n/paid-promotion-catalog.ts'),
    './assets-catalog': loadTs('resources/js/i18n/assets-catalog.ts'),
    './promotion-catalog': promotionCatalog,
    './transfer-catalog': loadTs('resources/js/i18n/transfer-catalog.ts'),
    './account-catalog': loadTs('resources/js/i18n/account-catalog.ts'),
    './client-polish-catalog': loadTs('resources/js/i18n/client-polish-catalog.ts'),
});
const adminCatalog = loadTs('resources/js/i18n/admin-catalog.ts', {
    './promotion-catalog': promotionCatalog,
});
const i18n = loadTs('resources/js/i18n/index.ts', {
    './client-polish-catalog': loadTs('resources/js/i18n/client-polish-catalog.ts'),
    './catalog': { catalog },
    './admin-catalog': adminCatalog,
});
const locales = ['en', 'zh-CN', 'ms', 'es'];
const admin = loadTs('resources/js/i18n/admin.ts', { './index': i18n });

test('daily chart date labels reserve endpoint spacing for every supported date range', () => {
    const { chartDateLabelIndices } = loadTs('resources/js/lib/funds-chart.ts');
    assert.deepEqual(chartDateLabelIndices(0, 612), []);
    assert.deepEqual(chartDateLabelIndices(1, 612), [0]);
    assert.deepEqual(chartDateLabelIndices(30, 612), [0, 4, 8, 12, 17, 21, 25, 29]);
    for (let count = 2; count <= 366; count++) {
        const width = Math.max(760, count * 12) - 148;
        const indices = chartDateLabelIndices(count, width);
        assert.equal(indices[0], 0);
        assert.equal(indices.at(-1), count - 1);
        assert.ok(indices.length <= 8);
        for (let i = 1; i < indices.length; i++) {
            assert.ok((indices[i] - indices[i - 1]) * width / count >= 64, `Overlapping labels at ${count} days`);
        }
    }
});

test('daily funds charts keep money exact and only normalize bounded SVG coordinates', () => {
    const { chartUnits, chartDecimal, fundsChartScale } = loadTs('resources/js/lib/funds-chart.ts');
    assert.equal(chartUnits('999999999999.12345678'), 99999999999912345678n);
    assert.equal(chartDecimal(chartUnits('-100.01000001')), '-100.01000001');
    const scale = fundsChartScale(['-100.00000000', '100.00000000']);
    assert.equal(scale.position('-100'), 1);
    assert.equal(scale.position('100'), 0);
    assert.equal(scale.position('0'), 0.5);
    assert.deepEqual(scale.ticks, ['100.00000000', '50.00000000', '0.00000000', '-50.00000000', '-100.00000000']);
    const huge = fundsChartScale(['999999999999999.99999999']);
    assert.equal(huge.position('999999999999999.99999999'), 0);
    assert.equal(huge.position('0'), 1);
    assert.equal(fundsChartScale(['0', '0']).position('0'), 1);
    assert.equal(fundsChartScale(['-1']).position('0'), 0);
    assert.throws(() => chartUnits('1e5'));
    assert.throws(() => chartUnits('1.000000001'));
});

test('platform overview renders real daily charts and multi-company date filters without demo metrics', () => {
    const page = readFileSync('resources/js/pages/platform/Dashboard.tsx', 'utf8');
    assert.ok(page.includes('<DailyFundsChart'));
    assert.ok(page.includes('hasNet &&'));
    assert.ok(page.includes('scope:'));
    assert.ok(page.includes('form.data.companies'));
    assert.ok(page.includes('<AdminDateInput'));
    const dateInput = readFileSync('resources/js/components/admin/AdminDateInput.tsx', 'utf8');
    assert.ok(dateInput.includes('<DialogContent'));
    assert.ok(!dateInput.includes('type="date"'));
    assert.ok(!page.includes('18.4K'));
    assert.ok(!page.includes('Static demo data'));
    const { fundsLabels } = loadTs('resources/js/lib/funds-chart.ts');
    for (const key of [...Object.values(fundsLabels), 'Daily retained funds', 'Daily inflow and outflow']) {
        assert.ok(adminCatalog.adminCatalog[key], key);
    }
});

test('admin company terminology preserves internal tenant route and permission identifiers', () => {
    assert.ok(Object.values(adminCatalog.adminCatalog).every((value) => !value.includes('租户')));
    for (const [key, value] of Object.entries({
        Tenants: '公司管理',
        'Create tenant': '创建公司',
        'Search tenants': '搜索公司',
        'Suspend tenant': '暂停公司',
        'Reactivate tenant': '恢复公司',
    })) {
        assert.equal(adminCatalog.adminCatalog[key], value);
    }
    assert.equal(adminCatalog.adminEnglish.Tenants, 'Company management');
    const nav = readFileSync('resources/js/layouts/PlatformLayout.tsx', 'utf8');
    assert.ok(nav.includes("href: '/platform/tenants'"));
    const routes = readFileSync('routes/platform.php', 'utf8');
    assert.ok(routes.includes("admin.scope:platform,tenant.read"));
});

test('withdrawal accepts a typed address and exact amount and exposes localized paginated history', () => {
    const page = readFileSync('resources/js/pages/user/Withdraw.tsx', 'utf8');
    const history = readFileSync('resources/js/components/user/WithdrawalHistory.tsx', 'utf8');
    assert.ok(!page.includes('withdrawal-destinations'));
    assert.ok(!page.includes('destination_id'));
    assert.ok(page.includes('id="withdrawal-address"'));
    assert.ok(page.includes('id="withdrawal-amount"'));
    assert.ok(page.includes('confirmed: true'));
    assert.ok(!page.includes('<WithdrawalHistory'));
    assert.ok(page.includes('href="/wallet/withdrawals"'));
    assert.ok(page.includes('absolute right-0 top-0'));
    const historyPage = readFileSync('resources/js/pages/user/WithdrawalHistory.tsx', 'utf8');
    assert.ok(historyPage.includes('<HistoryList history={history} />'));
    assert.ok(historyPage.includes('backHref="/wallet/withdraw"'));
    assert.ok(history.includes("'/wallet/withdrawals'"));
    assert.ok(history.includes('preserveState: true'));
    assert.ok(history.includes('order.maskedAddress'));
    for (const match of (page + history).matchAll(/\bt\('([^']+)'/g))
        assert.equal(catalog[match[1]]?.length, 3, match[1]);
    const { withdrawalRemainder } = loadTs('resources/js/lib/exact-amount.ts');
    assert.equal(withdrawalRemainder('0.01', '0.01000000'), '0.00000000');
    assert.equal(withdrawalRemainder('20.01', '250.00000000'), '229.99000000');
    assert.equal(withdrawalRemainder('999999999999.99', '999999999999.99000001'), '0.00000001');
    for (const amount of ['0', '-1', '0.001', '0.02', 'NaN', '1e3'])
        assert.equal(withdrawalRemainder(amount, '0.01000000'), null, amount);
});

test('SaaS manual receipt is an explicit localized confirmation and company topups stay read-only', () => {
    const page = readFileSync('resources/js/pages/platform/Topups.tsx', 'utf8');
    assert.ok(page.includes('wallet_topups.confirm'));
    assert.ok(!page.includes('tx_hash'));
    assert.ok(page.includes('/topups/${order.id}/confirm'));
    assert.ok(page.includes('confirmed: false'));
    assert.ok(page.includes('crypto.randomUUID()'));
    for (const match of page.matchAll(/\bt\('([^']+)'/g))
        assert.ok(adminCatalog.adminCatalog[match[1]] || catalog[match[1]], match[1]);
    const companyRoutes = readFileSync('routes/admin.php', 'utf8');
    assert.ok(!companyRoutes.includes('topups/{topup}/verify'));
    assert.ok(!companyRoutes.includes('topups/{topup}/confirm'));
});

test('deposit activation uses a direct localized entry and exact editable minimum', () => {
    const { meetsTopupMinimum } = loadTs('resources/js/lib/exact-amount.ts');
    for (const value of ['99.99', '0', '-100', '100.001', '1e3', 'NaN'])
        assert.equal(meetsTopupMinimum(value, '100.00000000'), false, value);
    for (const value of ['100', '100.00', '100.01', '250.99'])
        assert.equal(meetsTopupMinimum(value, '100.00000000'), true, value);
    assert.equal(meetsTopupMinimum('123.45', '123.45678901'), false);
    assert.equal(meetsTopupMinimum('123.46', '123.45678901'), true);
    const wallet = readFileSync('resources/js/pages/user/Wallet.tsx', 'utf8');
    const dashboard = readFileSync('resources/js/pages/user/Dashboard.tsx', 'utf8');
    assert.ok(!wallet.includes("router.post('/wallet/activate')"));
    assert.ok(dashboard.includes("label: t('Activate wallet'), href: '/security-deposit'"));
    assert.ok(dashboard.includes('if (!wallet.activationSatisfied)'));
    assert.ok(dashboard.includes("title={t('Set up your wallet')}"));
    assert.ok(dashboard.includes("title={t('Account pending activation')}"));
    assert.equal(catalog['Pending activation'][0], '待激活');
    const form = readFileSync('resources/js/components/user/DepositTopupForm.tsx', 'utf8');
    assert.ok(!form.includes("t('Deposit top-up is currently unavailable.')"));
    assert.ok(form.includes('disabled={!enabled || !valid || form.processing}'));
    assert.ok(form.includes("form.post('/security-deposit/top-ups')"));
    for (const match of form.matchAll(/\bt\('([^']+)'/g))
        assert.equal(catalog[match[1]]?.length, 3, match[1]);
});

test('unfunded deposit notices interpolate the actual remaining amount and asset in every locale', () => {
    const React = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const exactAmount = loadTs('resources/js/lib/exact-amount.ts');
    const { default: SecurityDeposit } = loadTs('resources/js/pages/user/SecurityDeposit.tsx', {
        '@/i18n': { ...i18n, useClientTranslation: () => ({ i18n: i18n.clientI18n }) },
        '@/lib/exact-amount': exactAmount,
        '@/lib/system-money': loadTs('resources/js/lib/system-money.ts', { './exact-amount': exactAmount }),
        '@inertiajs/react': { Head: () => null, Link: 'a', useForm: () => ({}), usePage: () => ({ props: { errors: {} } }) },
        '@/components/user/UserMoney': { MoneyDisplay: () => null },
        '@/components/user/UserPageHeader': { UserPageHeader: () => null },
        '@/components/user/UserStatusBanner': { UserStatusBanner: ({ title, description }) => React.createElement('section', null, title, description) },
        '@/components/ui/button': { Button: 'button' },
        '@/layouts/UserLayout': { UserLayout: ({ children }) => children },
        '@/components/user/DepositRefundControls': { DepositRefundControls: () => null },
        '@/components/user/DepositTopupForm': { DepositTopupForm: () => null },
    });
    const previous = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            for (const amount of ['300.00000000', '125.50000000']) {
                const html = renderToStaticMarkup(React.createElement(SecurityDeposit, { preview: {
                    current: { amount: '0.00000000', asset: 'USDT' },
                    remaining: { amount, asset: 'USDT' },
                    available: { amount: '0.00000000', asset: 'USDT' },
                    minimumTopup: { amount, asset: 'USDT' },
                    canFund: false, satisfied: false, agentExempt: false, refund: {},
                } }));
                assert.ok(html.includes(exactAmount.displayMoney(amount)));
                assert.ok(html.includes('USDT'));
                assert.ok(!html.includes('{{'));
            }
        }
    } finally { void i18n.clientI18n.changeLanguage(previous); }
});

test('the funded deposit banner displays actual deposited money instead of a generic success message', () => {
    const page = readFileSync('resources/js/pages/user/SecurityDeposit.tsx', 'utf8');
    const funded = page.split('preview.satisfied ? (')[1].split(') : !preview.canFund ? (')[0];
    assert.ok(funded.includes("t('Security deposit')"));
    assert.ok(funded.includes('<MoneyDisplay {...preview.current} />'));
    assert.ok(!funded.includes('preview.required'));
    assert.ok(!funded.includes('preview.available'));
    assert.ok(!funded.includes("t('Requirement met')"));
    assert.equal(catalog['Security deposit'].length, 3);
    assert.ok(page.includes('<DepositRefundControls refund={preview.refund} />'));
});

test('password recovery has a real login entry and explicit reset confirmation without persisting credentials', () => {
    const login = readFileSync('resources/js/pages/user/Login.tsx', 'utf8');
    const forgot = readFileSync('resources/js/pages/user/ForgotPassword.tsx', 'utf8');
    const reset = readFileSync('resources/js/pages/user/ResetPassword.tsx', 'utf8');
    assert.ok(login.includes('href="/forgot-password"'));
    assert.ok(forgot.includes("form.post('/forgot-password'"));
    assert.ok(reset.includes('form.post(`/forgot-password/${reset.id}`'));
    assert.ok(reset.includes('confirmed: false'));
    assert.ok(reset.includes('autoComplete="new-password"'));
    assert.ok(!`${forgot}${reset}`.includes('localStorage'));
    for (const match of `${forgot}${reset}`.matchAll(/\bt\('([^']+)'/g))
        assert.equal(catalog[match[1]]?.length, 3, match[1]);
});

test('account information contains real localized profile and contact forms without persisting credentials', () => {
    const page = readFileSync('resources/js/pages/user/Security.tsx', 'utf8');
    const forms = readFileSync('resources/js/components/user/AccountInformationForms.tsx', 'utf8');
    assert.ok(page.includes("t('Account and security')"));
    assert.ok(page.includes('/account/security/password'));
    for (const route of ['/account/information/name', '/account/information/contacts'])
        assert.ok(forms.includes(route));
    assert.ok(forms.includes('confirmed: false'));
    assert.ok(!forms.includes('localStorage'));
    for (const match of (page + forms).matchAll(/\bt\('([^']+)'\)/g)) {
        assert.equal(catalog[match[1]]?.length, 3, match[1]);
    }
});

test('my invitations contains team totals and links to three real detail pages', () => {
    const page = readFileSync('resources/js/pages/user/Promotion.tsx', 'utf8');
    for (const route of [
        '/promotion/daily',
        '/promotion/direct',
        '/promotion/commissions',
    ]) {
        assert.ok(page.includes(route));
    }
    for (const section of ['invitations', 'daily', 'direct']) {
        assert.ok(page.includes(`section === '${section}' &&`));
    }
    assert.ok(page.includes('`/promotion/${section}`'));
    const statement = readFileSync('resources/js/pages/user/PromotionCommissions.tsx', 'utf8');
    assert.ok(statement.includes('reportMoney(row.amount)'));
    assert.ok(readFileSync('resources/js/components/user/PaidPromotionSummary.tsx','utf8').includes('id="team-summary"'));
    assert.ok(!page.includes("href: '/promotion/team'"));
    assert.ok(!statement.includes('router.post'));
    for (const key of ['Team overview', 'Daily data', 'Direct invitees', 'Commission details'])
        assert.equal(catalog[key].length, 3);
});

test('support chat uses private images, escaped messages and localized send controls', () => {
    const page = readFileSync('resources/js/components/support/SupportThread.tsx', 'utf8');
    assert.ok(page.includes('{message.text}'));
    assert.ok(!page.includes('dangerouslySetInnerHTML'));
    assert.ok(page.includes('src={message.imageUrl}'));
    assert.ok(page.includes('URL.revokeObjectURL'));
    assert.ok(page.includes('form.data.support_image'));
    assert.ok(page.includes('onNetworkError'));
    assert.ok(!page.includes('localStorage'));
    for (const match of page.matchAll(/\bt\('([^']+)'\)/g)) {
        assert.equal(catalog[match[1]]?.length, 3, match[1]);
    }
    assert.ok(readFileSync('resources/js/layouts/UserLayout.tsx', 'utf8').includes("? '/support'"));
    assert.ok(
        readFileSync('resources/js/layouts/TenantAdminLayout.tsx', 'utf8').includes(
            "permission: 'support.manage'",
        ),
    );
});

test('top-up instructions use the persisted exact amount and address in a locally generated QR modal', () => {
    const form = readFileSync('resources/js/pages/user/Topup.tsx', 'utf8');
    assert.ok(!form.includes('setReviewing'));
    assert.ok(!form.includes("t('Create payment instructions')"));
    assert.ok(form.includes('onSubmit={(event) =>'));
    assert.ok(form.includes('disabled={!canContinue || processing}'));
    assert.ok(form.includes('request_id: requestId.current'));
    const page = readFileSync('resources/js/pages/user/TopupStatus.tsx', 'utf8');
    const instructions = readFileSync('resources/js/components/user/DepositInstructions.tsx', 'utf8');
    for (const invariant of ['useState(true)', '<Dialog open={open}', '<QRCodeSVG', 'value={address}', 'marginSize={4}', "copy(exactAmount(amount), 'Amount')"]) assert.ok(instructions.includes(invariant), invariant);
    for (const invariant of ['amount={order.expectedAmount}', '!order.paymentDetected', "countdown !== '00:00'", "router.reload({ only: ['order'] })"]) assert.ok(page.includes(invariant), invariant);
    assert.ok(readFileSync('resources/js/pages/user/AssetFlow.tsx', 'utf8').includes('<DepositInstructions'));
    assert.ok(!page.includes('Math.random'));
    assert.ok(!page.includes('parseFloat'));
    for (const key of [
        'View payment instructions',
        'Deposit address QR code',
        'The full amount, including the identification decimal, will be credited. Enter every decimal digit shown; this is not a fee.',
        'Scan for the address, then enter the exact amount above. Use USDT on TRON (TRC20) only.',
    ])
        assert.equal(catalog[key].length, 3);
    const { exactAmount } = loadTs('resources/js/lib/exact-amount.ts');
    assert.equal(exactAmount('100.01000000'), '100.01');
    assert.equal(exactAmount('100.99000000'), '100.99');
    assert.equal(exactAmount('10000000000.99000000'), '10000000000.99');
});

test('promotion income credits USDT automatically and retains independent pagination', () => {
    const page = readFileSync('resources/js/pages/user/Promotion.tsx', 'utf8');
    for (const invariant of [
        'direct_page: p.directPage',
        'page: p.page',
        'page: 1',
        'promotion-stats-daily',
        'Commission is automatically credited to your USDT balance.',
    ])
        assert.ok(page.includes(invariant), invariant);
    assert.ok(page.includes('p.page > 1 || p.hasMore'));
    assert.ok(page.includes('p.directPage > 1 || p.hasMoreDirect'));
    for (const key of [
        'How team totals are calculated',
        'No direct invitees yet.',
        'Could not copy.',
    ])
        assert.equal(catalog[key].length, 3);
    const css = readFileSync('resources/css/promotion.css', 'utf8');
    assert.ok(css.includes('.promotion-page'));
    assert.ok(css.includes('repeat(2, minmax(0, 1fr))'));
    assert.ok(css.includes('repeat(3, minmax(0, 1fr))'));
});

test('consumer company header preserves the company identity without an extra product suffix', () => {
    const source = readFileSync('resources/js/layouts/UserLayout.tsx', 'utf8');
    assert.ok(source.includes('src={tenant.branding.logoUrl}'));
    assert.ok(source.includes('alt={companyName}'));
    assert.ok(source.includes('value1: companyName'));
    assert.ok(!source.includes('cardLabel'));
});

test('wallet transfers retain exact signed amounts and localized directions', () => {
    const { walletActivityItems } = loadTs('resources/js/lib/wallet-activity.ts');
    const items = walletActivityItems(
        ['-10.25000000', '10.25000000'].map((amount, index) => ({
            id: String(index),
            eventType: 'WALLET_TRANSFER',
            amount,
            asset: 'USD',
            postedAt: '2026-09-11',
        })),
    );
    assert.deepEqual(
        items.map((item) => [item.title, item.direction, item.amount]),
        [
            ['Transfer sent', 'DEBIT', '-10.25000000'],
            ['Transfer received', 'CREDIT', '10.25000000'],
        ],
    );
    for (const key of [
        'Transfer',
        'Transfer sent',
        'Transfer received',
        'Confirm transfer',
        'Review transfer',
    ]) {
        assert.equal(catalog[key].length, 3);
        assert.ok(catalog[key].every((value) => value && value !== key));
    }
});

test('company settings omits technical tenant header copy and uses company-facing labels', () => {
    const settings = readFileSync('resources/js/pages/tenant-admin/Settings.tsx', 'utf8');
    assert.ok(!settings.includes("eyebrow={t('Tenant configuration')}"));
    assert.ok(
        !settings.includes(
            'Controlled white-label and operating configuration shared by all tenant surfaces.',
        ),
    );
    const before = i18n.clientI18n.language;
    for (const locale of ['zh-CN', 'en']) {
        void i18n.clientI18n.changeLanguage(locale);
        for (const key of [
            'TENANT_OWNER',
            'TENANT_ADMIN',
            'TENANT_SUPPORT',
            'TENANT_FINANCE',
            'TENANT_REVIEWER',
            'Tenant settings',
            'Tenant default',
            'Tenant offering',
            'Tenant routing',
            'Tenant workspace',
        ]) {
            assert.doesNotMatch(admin.t(key), /租户|tenant/i);
        }
        assert.equal(
            admin.t('{{value1}} administration', { value1: 'Tenant Example' }),
            locale === 'en' ? 'Tenant Example administration' : 'Tenant Example 管理后台',
        );
    }
    void i18n.clientI18n.changeLanguage(before);
});

test('exact amount helper preserves financial precision', () => {
    const { exactAmount } = loadTs('resources/js/lib/exact-amount.ts');
    for (const [input, expected] of [
        ['0.00000000', '0'],
        ['15.00000000', '15'],
        ['100.01000000', '100.01'],
        ['999999999999.12345678', '999999999999.12345678'],
        ['-0.00000001', '-0.00000001'],
    ]) {
        assert.equal(exactAmount(input), expected);
    }
});

test('money presentation uses two decimals with exact half-up rounding, never floating point', () => {
    const { displayMoney } = loadTs('resources/js/lib/exact-amount.ts');
    for (const [input, expected] of [
        ['0', '0.00'],
        ['20.00000000', '20.00'],
        ['2.1', '2.10'],
        ['1.00499999', '1.00'],
        ['1.00500000', '1.01'],
        ['99.99999999', '100.00'],
        ['999999999999.99999999', '1000000000000.00'],
        ['-1.00500000', '-1.01'],
        ['-0.00000001', '0.00'],
        ['', ''],
        ['invalid', 'invalid'],
    ])
        assert.equal(displayMoney(input), expected);
});

test('timed refunds replace manual checking and retain read-only card history', () => {
    const controls = readFileSync('resources/js/components/user/DepositRefundControls.tsx', 'utf8');
    const cards = readFileSync('resources/js/components/user/CardManagementActions.tsx', 'utf8');
    assert.ok(!controls.includes("action: 'check'"));
    assert.ok(!controls.includes('Check and complete refund'));
    assert.ok(controls.includes('refund.eligibleAt'));
    assert.ok(controls.includes('refund.serverNow'));
    assert.ok(controls.includes('window.clearInterval(timer)'));
    assert.ok(controls.includes("router.reload({ only: ['preview'] })"));
    assert.match(cards, /card\.refundLocked\s*\?\s*\['transactions'\]/);
});

test('refund notices are localized separate bullet points on the page and in confirmation', () => {
    const controls = readFileSync('resources/js/components/user/DepositRefundControls.tsx', 'utf8');
    const confirmation = readFileSync('resources/js/components/user/FinancialConfirmation.tsx', 'utf8');
    assert.ok(controls.includes('warning.map'));
    assert.ok(controls.includes('warning={warning}'));
    assert.ok(confirmation.includes('Array.isArray(warning)'));
    assert.ok(confirmation.includes('<DialogDescription asChild>'));
    for (const key of [
        'Applying for a deposit refund freezes your cards and locks card actions.',
        'Cards cannot be used during the deposit refund period. Only transaction history is available.',
        'After the waiting period and confirmed card freezing, your deposit returns automatically to your wallet.',
    ]) {
        assert.ok(controls.includes(key));
        for (const locale of locales.slice(1)) assert.notEqual(i18n.clientI18n.t(key, { lng: locale }), key);
    }
    assert.ok(!controls.includes('A deposit refund does not affect your commission.'));
    const commissionNotice = 'During and after a deposit refund, commission can still be earned but cannot be transferred to your wallet or withdrawn. Existing wallet balance can still be withdrawn.';
    assert.ok(!controls.includes(commissionNotice));
    const promotion = readFileSync('resources/js/pages/user/Promotion.tsx', 'utf8');
    assert.ok(!promotion.includes('canTransfer'));
    assert.ok(!promotion.includes('commissionRefundRestricted'));
    assert.ok(!promotion.includes(commissionNotice));
});

test('deposit history is a separate page with a top-right entry and no embedded refund list', () => {
    const page = readFileSync('resources/js/pages/user/SecurityDeposit.tsx', 'utf8');
    const controls = readFileSync('resources/js/components/user/DepositRefundControls.tsx', 'utf8');
    const history = readFileSync('resources/js/pages/user/SecurityDepositHistory.tsx', 'utf8');
    assert.ok(page.includes('href="/security-deposit/history"'));
    assert.ok(page.includes('absolute right-0 top-0'));
    assert.ok(!controls.includes('refund.history'));
    assert.ok(controls.includes("action: 'request'"));
    assert.ok(history.includes('backHref="/security-deposit"'));
    assert.ok(history.includes('history.lastPage > 1'));
    for (const key of ['Security deposit history', 'No security deposit history yet.']) {
        assert.ok(key in catalog);
        for (const locale of locales.slice(1)) assert.notEqual(i18n.clientI18n.t(key, { lng: locale }), key);
    }
});

test('deposit amount and refund-wait configuration belong to SaaS company details only', () => {
    const platform = readFileSync('resources/js/components/admin/CompanyDepositSettings.tsx', 'utf8');
    const company = readFileSync('resources/js/pages/tenant-admin/Settings.tsx', 'utf8');
    const detail = readFileSync('resources/js/pages/platform/TenantDetail.tsx', 'utf8');
    assert.ok(platform.includes('deposit-settings'));
    assert.ok(platform.includes('required_security_deposit_amount: amount'));
    assert.ok(platform.includes('waitDays === null'));
    assert.ok(platform.includes('canManage ?'));
    assert.ok(detail.includes('canManage={canManage}'));
    assert.ok(company.includes('{configurationBase ? ('));
    assert.ok(company.includes('<ConfigurationForm'));
    const formBoundary = readFileSync('resources/js/components/admin/CompanyConfiguration.tsx', 'utf8');
    assert.ok(formBoundary.includes('configurationReadOnly'));
    assert.ok(formBoundary.includes('disabled={configurationReadOnly}'));
    assert.ok(company.includes('settings.business.depositRefundWaitDays'));
    for (const key of ['Company security deposit settings', 'Deposit refund waiting period (days)', 'Save deposit settings', 'Not configured', 'Company security deposit settings saved.']) {
        assert.ok(i18n.clientResources.en.admin[key]);
        assert.ok(i18n.clientResources['zh-CN'].admin[key]);
    }
});

test('Me delegates financial navigation to Assets and financial returns go home', () => {
    const account = readFileSync('resources/js/pages/user/Account.tsx', 'utf8');
    assert.ok(!account.includes("href: '/wallet'"));
    assert.ok(!account.includes("href: '/security-deposit'"));
    for (const name of ['Topup', 'Transfer', 'Withdraw', 'SecurityDeposit']) {
        const page = readFileSync(`resources/js/pages/user/${name}.tsx`, 'utf8');
        assert.ok(page.includes('backHref="/dashboard"'), name);
        assert.ok(!page.includes('backHref="/wallet"'), name);
    }
    for (const name of ['Transfer', 'SecurityDepositSuccess']) {
        const page = readFileSync(`resources/js/pages/user/${name}.tsx`, 'utf8');
        assert.ok(page.includes('<Link href="/dashboard">{t(\'Back to home\')}</Link>'), name);
        assert.ok(!page.includes('href="/wallet"'), name);
    }
    const status = readFileSync('resources/js/pages/user/TopupStatus.tsx', 'utf8');
    assert.ok(status.includes("const backHref = depositFlow ? '/security-deposit' : '/dashboard'"));
    assert.ok(status.includes("t('Back to home')"));
    assert.ok(!status.includes("'/wallet'"));
    // Deposit continuation and new-payment actions remain their own business steps.
    assert.ok(status.includes("? '/security-deposit'"));
    assert.ok(status.includes("? '/wallet/top-up'"));
});

test('consumer header language and support icons share responsive sizing and stroke width', () => {
    const layout = readFileSync('resources/js/layouts/UserLayout.tsx', 'utf8');
    const language = readFileSync('resources/js/components/user/LanguageSwitcher.tsx', 'utf8');
    const css = readFileSync('resources/css/app.css', 'utf8');
    assert.ok(layout.includes('className="user-header-action"'));
    assert.match(language, /variant === 'icon'\s*\? 'user-header-action'/);
    assert.ok(layout.includes('<MessageSquare strokeWidth={2.2}'));
    assert.ok(language.includes('<Globe2 strokeWidth={2.2}'));
    assert.match(css, /\.user-header-action \{[^}]*width: clamp\(44px, 9\.6cqw, 72px\);[^}]*height: clamp\(44px, 9\.6cqw, 72px\);/);
    assert.match(css, /\.user-header-action svg \{[^}]*width: clamp\(24px, 5\.867cqw, 44px\);[^}]*height: clamp\(24px, 5\.867cqw, 44px\);/);
});

test('card controls expose primary unfreeze for frozen cards and permission-scoped management in More', () => {
    const source = readFileSync('resources/js/components/user/CardManagementActions.tsx', 'utf8');
    assert.match(source, /const actions = \[\s*'reveal',\s*card.state === 'Frozen' \? 'unfreeze' : 'load',\s*'return',\s*'transactions',?\s*\]/);
    assert.ok(source.includes("const moreActions = ['freeze', 'unfreeze', 'holder', 'cancel'].filter"));
    const controls = source.slice(source.indexOf('data-card-actions'), source.indexOf('<Dialog\n'));
    assert.ok(controls.includes('capabilities.includes(action)'));
    assert.ok(controls.includes("t('More')"));
    assert.ok(controls.includes("open('history')"));
    assert.ok(controls.includes('card.pendingOperationCount'));
    assert.ok(controls.includes('!card.refundLocked'));
});

test('card management dialogs omit the generic operation subtitle', () => {
    const source = readFileSync('resources/js/components/user/CardManagementActions.tsx', 'utf8');
    assert.ok(source.includes('aria-describedby={undefined}'));
    assert.ok(!source.includes('Changes are confirmed by the card issuer. Do not submit again while a result is pending.'));
});

test('holder edits prefill privately and send only changes with complete dependent validation groups', () => {
    const { cardholderChanges } = loadTs('resources/js/lib/cardholder-changes.ts');
    const original = {email: 'old@example.test', mobile: '13800138000', mobile_country_code: 'CN', residential_country_code: 'MY', residential_state: 'Selangor', residential_city: 'Petaling Jaya', residential_address: 'Old street', residential_postal_code: '46000'};
    assert.deepEqual(cardholderChanges(original, original), {});
    assert.deepEqual(cardholderChanges({...original, email: 'new@example.test'}, original), {email: 'new@example.test'});
    assert.deepEqual(cardholderChanges({...original, mobile: '13900139000'}, original), {mobile: '13900139000', mobile_country_code: 'CN'});
    assert.deepEqual(cardholderChanges({...original, residential_address: 'New street'}, original), {residential_address: 'New street', residential_country_code: 'MY', residential_state: 'Selangor', residential_city: 'Petaling Jaya', residential_postal_code: '46000'});
    assert.deepEqual(cardholderChanges({...original, email: ''}, original), {email: ''});
    const source = readFileSync('resources/js/components/user/CardManagementActions.tsx', 'utf8');
    assert.ok(source.includes("post(card.id, { action: 'holder_details' })"));
    assert.ok(source.includes('setOriginalFields(values)'));
    assert.ok(source.includes('current !== generation.current || !visible.current'));
    assert.ok(source.includes('setOriginalFields({})'));
    assert.ok(!source.includes('localStorage'));
    assert.ok(!source.includes('sessionStorage'));
    for (const locale of ['en', 'zh-CN', 'ms', 'es']) {
        for (const key of ['Saved information is shown below. Approved names cannot be changed. This does not replace the cardholder.', 'Loading cardholder information…', 'Cardholder information could not be loaded. Please close and try again.']) {
            assert.ok(i18n.clientResources[locale].translation[key]);
        }
    }
});

test('card reload displays the server wallet balance and confirms the same server quote in one submission', () => {
    const source = readFileSync('resources/js/components/user/CardManagementActions.tsx', 'utf8');
    const page = readFileSync('resources/js/pages/user/Cards.tsx', 'utf8');
    assert.ok(page.includes('availableBalance={props.availableBalance}'));
    assert.ok(page.includes('walletAsset={props.walletAsset}'));
    assert.ok(source.includes('data-reload-wallet-balance'));
    assert.ok(source.includes('availableBalance !== null && walletAsset'));
    assert.ok(source.includes("t('Available Wallet balance')"));
    assert.ok(source.includes("t(active === 'load' ? 'Reload' : 'Confirm')"));
    assert.ok(source.includes("result = await post(card.id, { action: 'confirm', order_id: quoted.id })"));
    assert.ok(!source.includes("'Get reload quote'"));
    assert.match(source, /order\?\.state === 'quoted'\s*\? 'confirm'\s*:\s*'quote'/);
    assert.ok(source.includes("input.current_password = password"));
    assert.ok(source.includes('input.confirmed = confirmed'));
    for (const locale of ['en', 'zh-CN', 'ms', 'es']) assert.ok(i18n.clientResources[locale].translation['Confirm card reload']);
});

test('opening a card starts with product selection even with a single product and preserves pending application gates', () => {
    const page = readFileSync('resources/js/pages/user/Cards.tsx', 'utf8');
    const trigger = page.slice(page.indexOf('id="open-card-application"'), page.indexOf('aria-haspopup="dialog"', page.indexOf('id="open-card-application"')));
    assert.ok(trigger.includes('setChoosingCard(true)'));
    assert.ok(!trigger.includes('setApplicationProductId'));
    assert.ok(page.includes('data-card-product-picker'));
    assert.ok(page.includes('setApplicationProductId(option.id)'));
    assert.ok(page.includes('activeApplication.productId !== option.id'));
    assert.ok(page.includes('!option.readyForSetup'));
    assert.match(page, /!props\.demo\s*&&\s*!unresolved/);
    assert.ok(page.includes('!props.refundPending'));
    assert.ok(!page.includes('products.length > 1'));
    for (const locale of ['en', 'zh-CN', 'ms', 'es']) {
        for (const key of ['Open this card', 'Choose a card product before entering cardholder information.', 'Continue your existing application before choosing another card product.']) assert.ok(i18n.clientResources[locale].translation[key]);
    }
});

test('unissued ready applications can edit through private prefill but uncertain applications stay locked', () => {
    const page = readFileSync('resources/js/pages/user/Cards.tsx', 'utf8');
    const hook = readFileSync('resources/js/hooks/useCardholderMaterialsForm.ts', 'utf8');
    assert.ok(page.includes("editingMaterials && application?.state === 'ready'"));
    assert.ok(page.includes("t('Edit card application information')"));
    assert.ok(page.includes('updateRequestId={application?.requestId ?? undefined}'));
    assert.ok(page.includes('materialReadGeneration.current++'));
    assert.match(page, /front: null,\s*back: null/);
    assert.ok(hook.includes('materialTextFields.map'));
    assert.ok(hook.includes("cache: 'no-store'"));
    assert.ok(!hook.includes('localStorage'));
    for (const locale of ['en', 'zh-CN', 'ms', 'es']) assert.ok(i18n.clientResources[locale].translation['Edit card application information']);
});

test('card management dynamic action and confirmation labels cover every locale', () => {
    for (const key of [
        'Confirm',
        'Close',
        'View CVV',
        'Card transactions',
        'Edit cardholder',
        'Reload card',
        'Return card balance',
        'Cancel card',
        'Freeze card',
        'Unfreeze card',
        'Quote ready',
        'Completed',
        'Operation declined',
        'Quote expired',
        'Awaiting confirmation',
        'Review quote',
        'Get reload quote',
    ]) {
        for (const locale of locales)
            assert.ok(i18n.clientResources[locale].translation[key]?.trim(), locale + ': ' + key);
    }
});
const adminSources = [
    ...[
        'resources/js/pages/platform',
        'resources/js/pages/tenant-admin',
        'resources/js/components/admin',
    ].flatMap((dir) =>
        readdirSync(dir)
            .filter((file) => file.endsWith('.tsx'))
            .map((file) => `${dir}/${file}`),
    ),
    'resources/js/layouts/PlatformLayout.tsx',
    'resources/js/layouts/TenantAdminLayout.tsx',
    'resources/js/layouts/AdminAuthLayout.tsx',
];

test('admin has exactly English and Chinese namespaces with matching nonempty interpolation resources', () => {
    assert.equal(i18n.clientResources.ms.admin, undefined);
    assert.equal(i18n.clientResources.es.admin, undefined);
    const en = i18n.clientResources.en.admin,
        zh = i18n.clientResources['zh-CN'].admin;
    assert.deepEqual(Object.keys(en).sort(), Object.keys(zh).sort());
    for (const key of Object.keys(en)) {
        assert.ok(en[key].trim() && zh[key].trim(), key);
        assert.deepEqual(tokens(en[key]), tokens(zh[key]), key);
    }
});

test('admin static page copy and navigation labels have complete translations', () => {
    const missing = [],
        raw = [];
    const allowed =
        /^(?:USDT|USD|TRC20|REGULAR|Aperture Platform|TEST|MOCK|acme|[\s\d.,+…—·:()*/%-]+)$/;
    for (const path of adminSources) {
        const file = ts.createSourceFile(
            path,
            readFileSync(path, 'utf8'),
            ts.ScriptTarget.Latest,
            true,
            ts.ScriptKind.TSX,
        );
        const check = (text) => {
            const value = text.replace(/\s+/g, ' ').trim();
            if (value && !allowed.test(value)) raw.push(`${path}: ${value}`);
        };
        const key = (text) => {
            if (!(text in i18n.clientResources.en.admin)) missing.push(`${path}: ${text}`);
        };
        const visit = (node) => {
            if (
                ts.isCallExpression(node) &&
                node.expression.getText(file) === 't' &&
                node.arguments[0] &&
                ts.isStringLiteral(node.arguments[0])
            )
                key(node.arguments[0].text);
            if (
                ts.isPropertyAssignment(node) &&
                ['label', 'description'].includes(node.name.getText(file)) &&
                ts.isStringLiteral(node.initializer)
            )
                key(node.initializer.text);
            if (ts.isJsxText(node)) check(node.text);
            if (
                ts.isJsxAttribute(node) &&
                ['aria-label', 'title', 'placeholder', 'alt', 'description', 'label'].includes(
                    node.name.text,
                ) &&
                node.initializer &&
                ts.isStringLiteral(node.initializer)
            )
                check(node.initializer.text);
            ts.forEachChild(node, visit);
        };
        visit(file);
    }
    assert.deepEqual(missing, []);
    assert.deepEqual(raw, []);
});

test('admin enum presentation translates without changing financial values or user-authored data', () => {
    const before = i18n.clientI18n.language;
    for (const locale of ['zh-CN', 'en']) {
        void i18n.clientI18n.changeLanguage(locale);
        for (const key of [
            'TENANT_OWNER',
            'PLATFORM_OWNER',
            'CREDITED',
            'UNKNOWN',
            'REQUIRES_REVIEW',
            'WRONG_AMOUNT',
            'CARD_ISSUE_FEE_HOLD',
            'RESUBMISSION_REQUIRED',
        ]) {
            assert.notEqual(admin.t(key), key, key);
        }
        for (const value of [
            '123456789012.12345678',
            'USD',
            'USDT',
            'PHOTONPAY',
            'My Custom Company',
            'customer@example.com',
            'stable-provider-id',
        ])
            assert.equal(admin.t(value), value);
    }
    void i18n.clientI18n.changeLanguage(before);
});

test('admin flash and onboarding labels have translations, and unknown errors are sanitized', () => {
    const files = ['app/Http/Controllers/Platform', 'app/Http/Controllers/TenantAdmin'].flatMap(
        (dir) =>
            readdirSync(dir)
                .filter((file) => file.endsWith('.php'))
                .map((file) => `${dir}/${file}`),
    );
    for (const path of files) {
        const source = readFileSync(path, 'utf8');
        for (const match of source.matchAll(/with\('success', '([^']+)'\)/g))
            assert.ok(match[1] in i18n.clientResources['zh-CN'].admin, match[1]);
    }
    const checklist = readFileSync(
        'app/Domain/Tenant/Services/TenantOnboardingStatusService.php',
        'utf8',
    );
    for (const match of checklist.matchAll(/'label' => '([^']+)'/g))
        assert.ok(match[1] in i18n.clientResources['zh-CN'].admin, match[1]);
    const before = i18n.clientI18n.language;
    void i18n.clientI18n.changeLanguage('zh-CN');
    assert.equal(
        admin.errorMessage('The email field must be a valid email address.'),
        '请输入有效的邮箱地址。',
    );
    assert.doesNotMatch(admin.errorMessage('secret-provider-response'), /secret-provider-response/);
    assert.equal(admin.errorMessage('This invitation has expired.'), '此邀请已过期。');
    void i18n.clientI18n.changeLanguage(before);
});
const cardLocality = loadTs('resources/js/lib/card-locality.ts');
const holderValidation = loadTs('resources/js/lib/cardholder-validation.ts', {'./card-locality': cardLocality});
const holderCountries = JSON.parse(
    readFileSync('public/data/card-geography/countries.json', 'utf8'),
);
const holderRegions = JSON.parse(readFileSync('public/data/card-geography/CN.json', 'utf8'));
const holderContext = { countries: holderCountries, regions: holderRegions, today: '2026-09-10' };
const holderData = {
    legal_last_name: 'Example',
    legal_first_name: 'Test',
    email: 'test@example.com',
    mobile: '13800138000',
    mobile_country_code: 'CN',
    nationality_country_code: 'CN',
    date_of_birth: '1990-01-01',
    document_type: 'passport',
    front: { type: 'image/png', size: 100 },
    back: null,
    residential_country_code: 'CN',
    residential_state: 'Anhui',
    residential_city: 'Fuyang',
    residential_address: '1 Test Road',
    residential_postal_code: '236000',
};

test('all country locality lists have a safe manual fallback while loading and invalid parents stay disabled', () => {
    for (const country of holderCountries) {
        const regions = JSON.parse(readFileSync(`public/data/card-geography/${country.code}.json`, 'utf8'));
        assert.equal(cardLocality.localityMode(undefined, '', 'residential_state'), 'disabled');
        assert.equal(cardLocality.localityMode(regions, '', 'residential_state'), regions.length ? 'select' : 'manual', country.code);
        if (!regions.length) assert.equal(cardLocality.localityMode(regions, 'Test Region', 'residential_city'), 'manual');
        for (const region of regions) assert.equal(cardLocality.localityMode(regions, region.value, 'residential_city'), region.cities.length ? 'select' : 'manual', `${country.code}/${region.value}`);
        assert.equal(cardLocality.localityMode(regions, '', 'residential_city'), 'disabled');
    }
    for (const value of ['', '../file', '<script>', 'City\nName', 'a'.repeat(51), '---']) assert.equal(cardLocality.validManualLocality(value), false);
    for (const value of ['板桥区', 'Macao', 'St. John’s', 'District 1']) assert.equal(cardLocality.validManualLocality(value), true);
    const tw = JSON.parse(readFileSync('public/data/card-geography/TW.json', 'utf8'));
    assert.equal(holderValidation.cardholderFieldError('residential_city', {...holderData, residential_country_code:'TW', residential_state:'New Taipei', residential_city:'Banqiao'}, {...holderContext, regions:tw}), undefined);
    assert.notEqual(holderValidation.cardholderFieldError('residential_city', {...holderData, residential_city:'Unlisted city'}, holderContext), undefined);
});

test('cardholder form does not collect or require identity number or a separate issuing country', () => {
    const form = readFileSync('resources/js/components/user/CardholderMaterialsForm.tsx', 'utf8');
    const hook = readFileSync('resources/js/hooks/useCardholderMaterialsForm.ts', 'utf8');
    assert.doesNotMatch(
        form,
        /document_country|Document issuing country|identity_number|Identity number/,
    );
    assert.doesNotMatch(hook, /document_country|identity_number/);
    assert.equal(holderValidation.cardholderFields.includes('document_country'), false);
    assert.equal(holderValidation.cardholderFields.includes('identity_number'), false);
    assert.deepEqual(holderValidation.cardholderErrors(holderData, holderContext), {});
    assert.equal(
        holderValidation.cardholderErrors(
            { ...holderData, nationality_country_code: '' },
            holderContext,
        ).nationality_country_code,
        'This field is required.',
    );
});

test('successful material submission continues the same application while errors preserve input and safe request identity', () => {
    const primitives = Object.fromEntries(
        [
            'Button',
            'FormField',
            'Input',
            'SearchSelect',
            'Select',
            'SelectContent',
            'SelectItem',
            'SelectTrigger',
            'SelectValue',
        ].map((name) => [name, name]),
    );
    const overrides = {
        '@/i18n': { ...i18n, useClientTranslation: () => undefined },
        '@/lib/cardholder-validation': holderValidation,
        '@/lib/card-locality': cardLocality,
        '@/hooks/useCardGeography': {
            countryOptions: () => [],
            placeOptions: () => [],
            useCardGeography: (file) => ({
                data: file === 'countries' ? holderCountries : holderRegions,
            }),
        },
        ...Object.fromEntries(
            ['button', 'form-field', 'input', 'search-select', 'select'].map((name) => [
                `@/components/ui/${name}`,
                primitives,
            ]),
        ),
    };
    const { CardholderMaterialsForm } = loadTs(
        'resources/js/components/user/CardholderMaterialsForm.tsx',
        overrides,
    );
    let sent;
    let continued = 0;
    let resets = 0;
    const form = {
        data: { ...holderData, request_id: 'stable-request', card_product_id: 'product' },
        errors: {},
        processing: false,
        clearErrors() {},
        transform() {},
        setData(key, value) {
            this.data[key] = value;
        },
        reset() {
            resets++;
        },
        post(url, options) {
            sent = { url, options };
        },
    };
    const tree = CardholderMaterialsForm({
        form,
        onAdded: () => {
            continued++;
        },
    });
    tree.props.onSubmit({ preventDefault() {} });
    assert.equal(sent.url, '/cards/cardholder');
    assert.equal(sent.options.preserveState, true);
    sent.options.onError({
        form: 'The cardholder addition could not be confirmed. Do not submit it again.',
    });
    assert.equal(form.data.request_id, 'stable-request');
    assert.equal(continued, 0);
    assert.equal(resets, 0);
    sent.options.onError({
        form: 'The cardholder could not be added. Check the details and try again.',
    });
    assert.notEqual(form.data.request_id, 'stable-request');
    assert.equal(form.data.legal_first_name, holderData.legal_first_name);
    assert.equal(resets, 0);
    sent.options.onSuccess();
    assert.equal(continued, 1);
    assert.equal(resets, 1);
});

test('cardholder contact errors reject the reported 1111 values and preserve optional phone', () => {
    const errors = holderValidation.cardholderErrors(
        { ...holderData, email: '1111', mobile: '1111' },
        holderContext,
    );
    assert.equal(errors.email, 'Enter a valid email address.');
    assert.equal(errors.mobile, 'Select a calling code and enter a valid mobile number.');
    assert.deepEqual(holderValidation.cardholderErrors(holderData, holderContext), {});
    assert.deepEqual(
        holderValidation.cardholderErrors({ ...holderData, mobile: '' }, holderContext),
        {},
    );
    assert.equal(
        holderValidation.cardholderErrors({ ...holderData, email: ' ' }, holderContext).email,
        'This field is required.',
    );
    for (const email of [
        'a@@example.com',
        'a b@example.com',
        'name@',
        '@example.com',
        'a'.repeat(30) + '@example.com',
    ])
        assert.ok(holderValidation.cardholderErrors({ ...holderData, email }, holderContext).email);
});

test('cardholder phone validation follows the selected prefix and accepts formatted numbers', () => {
    assert.equal(
        holderValidation.cardholderErrors(
            { ...holderData, mobile: '202-555-0123', mobile_country_code: 'US' },
            holderContext,
        ).mobile,
        undefined,
    );
    assert.ok(
        holderValidation.cardholderErrors(
            { ...holderData, mobile: '202-555-0123', mobile_country_code: 'HK' },
            holderContext,
        ).mobile,
    );
    for (const mobile of ['', '   ', '1111', '1380013800', '11111111111', 'call13800138000', '+8613800138000'])
        assert.ok(
            holderValidation.cardholderErrors({ ...holderData, mobile }, holderContext).mobile,
            mobile,
        );
    assert.ok(
        holderValidation.cardholderErrors(
            { ...holderData, mobile_country_code: 'ZZ' },
            holderContext,
        ).mobile,
    );
});

test('cardholder basic validation covers required fields names dates address and document bounds', () => {
    for (const [field, value] of [
        ['legal_first_name', '1111'],
        ['legal_last_name', 'a'.repeat(41)],
        ['date_of_birth', '2026-09-10'],
        ['date_of_birth', '2026-09-11'],
        ['date_of_birth', '2025-02-29'],
        ['residential_address', 'x'.repeat(101)],
        ['residential_postal_code', '中文'],
        ['residential_postal_code', '12345678901'],
        ['residential_city', 'Arbitrary city'],
        ['document_type', 'other'],
    ])
        assert.ok(
            holderValidation.cardholderErrors({ ...holderData, [field]: value }, holderContext)[
                field
            ],
            field,
        );
    for (const field of [
        'legal_first_name',
        'legal_last_name',
        'date_of_birth',
        'nationality_country_code',
        'residential_country_code',
        'residential_state',
        'residential_city',
        'residential_address',
        'residential_postal_code',
    ])
        assert.equal(
            holderValidation.cardholderErrors({ ...holderData, [field]: ' ' }, holderContext)[
                field
            ],
            'This field is required.',
            field,
        );
    assert.equal(
        holderValidation.cardholderErrors(
            { ...holderData, date_of_birth: '2000-02-29', legal_first_name: '测试' },
            holderContext,
        ).date_of_birth,
        undefined,
    );
});

test('cardholder upload checks require the correct sides and reject unsupported or oversized files', () => {
    for (const front of [
        null,
        { type: 'application/pdf', size: 100 },
        { type: 'image/png', size: 0 },
        { type: 'image/jpeg', size: 6 * 1024 * 1024 + 1 },
    ])
        assert.ok(holderValidation.cardholderErrors({ ...holderData, front }, holderContext).front);
    assert.equal(
        holderValidation.cardholderErrors(
            { ...holderData, front: { type: 'image/jpeg', size: 6 * 1024 * 1024 } },
            holderContext,
        ).front,
        undefined,
    );
    assert.equal(
        holderValidation.cardholderErrors(
            { ...holderData, document_type: 'id_card' },
            holderContext,
        ).back,
        'This field is required.',
    );
});

test('cardholder validation has complete localized messages and never mutates sensitive form data', () => {
    const data = {
        ...holderData,
        request_id: 'stable-uuid',
        email: '1111',
        mobile: '1111',
        legal_first_name: '123',
        legal_last_name: 'x'.repeat(41),
        date_of_birth: 'bad',
        front: { type: 'image/png', size: 9 * 1024 * 1024 },
        residential_address: 'x'.repeat(101),
        residential_postal_code: 'invalid#',
    };
    const before = structuredClone(data);
    const errors = holderValidation.cardholderErrors(data, holderContext);
    assert.deepEqual(data, before);
    for (const key of Object.values(errors).concat('Email must be at most 40 characters.')) {
        assert.ok(catalog[key], key);
        for (const locale of locales.slice(1))
            assert.notEqual(i18n.clientI18n.t(key, { lng: locale }), key);
    }
});
const geo = loadTs('resources/js/hooks/useCardGeography.ts');

test('geography choices translate labels but preserve canonical values and region membership', () => {
    const countries = JSON.parse(readFileSync('public/data/card-geography/countries.json', 'utf8'));
    const china = JSON.parse(readFileSync('public/data/card-geography/CN.json', 'utf8'));
    const anhui = china.find((state) => state.value === 'Anhui');
    assert.ok(anhui.cities.some((city) => city.value === 'Fuyang'));
    assert.equal(
        geo.placeOptions(anhui.cities, 'zh-CN').find((city) => city.value === 'Fuyang').label,
        '阜阳市',
    );
    for (const locale of locales) {
        const options = geo.countryOptions(countries, locale);
        assert.equal(
            options.find((option) => option.value === 'CN').label,
            new Intl.DisplayNames([locale], { type: 'region' }).of('CN'),
        );
        assert.ok(
            geo
                .countryOptions(countries, locale, true)
                .find((option) => option.value === 'CN')
                .label.startsWith('+86 '),
        );
        assert.equal(geo.placeOptions([anhui], locale)[0].value, 'Anhui');
        for (const key of [
            'Card user',
            'Billing address',
            'Nationality',
            'Country / region',
            'Document issuing country',
            'Mobile number (optional)',
        ])
            assert.ok(catalog[key]);
    }
});

test('every geography dropdown has unique canonical values and public reference files', () => {
    const countries = JSON.parse(readFileSync('public/data/card-geography/countries.json', 'utf8'));
    assert.equal(new Set(countries.map((country) => country.code)).size, countries.length);
    for (const country of countries) {
        const states = JSON.parse(
            readFileSync(`public/data/card-geography/${country.code}.json`, 'utf8'),
        );
        assert.equal(new Set(states.map((state) => state.value)).size, states.length, country.code);
        for (const state of states)
            assert.equal(
                new Set(state.cities.map((city) => city.value)).size,
                state.cities.length,
                `${country.code}/${state.value}`,
            );
    }
});
const tokens = (text) =>
    [...text.matchAll(/{{\s*([^}]+)\s*}}/g)].map((match) => match[1].trim()).sort();
const sources = [
    ...['resources/js/pages/user', 'resources/js/components/user'].flatMap((dir) =>
        readdirSync(dir)
            .filter((file) => file.endsWith('.tsx'))
            .map((file) => `${dir}/${file}`),
    ),
    'resources/js/layouts/UserLayout.tsx',
    'resources/js/layouts/PublicLayout.tsx',
    'resources/js/layouts/UserArticleLayout.tsx',
    'resources/js/pages/public/Landing.tsx',
    'resources/js/pages/errors/DomainError.tsx',
];

test('all four catalogs have complete nonempty resources and identical interpolation tokens', () => {
    assert.ok(Object.keys(catalog).length > 350);
    for (const [key, translations] of Object.entries(catalog)) {
        assert.doesNotMatch(key, /[\u3400-\u9fff]/, key);
        assert.equal(translations.length, 3, key);
        for (const value of translations) {
            assert.ok(value.trim(), key);
            assert.deepEqual(tokens(value), tokens(key), key);
        }
    }
    for (const locale of locales)
        assert.equal(
            Object.keys(i18n.clientResources[locale].translation).length,
            Object.keys(catalog).length,
        );
});

test('every literal translation call in consumer pages has all language resources', () => {
    const missing = [];
    for (const path of sources.concat(
        'resources/js/i18n/index.ts',
        'resources/js/lib/card-display-name.ts',
    )) {
        const file = ts.createSourceFile(
            path,
            readFileSync(path, 'utf8'),
            ts.ScriptTarget.Latest,
            true,
            ts.ScriptKind.TSX,
        );
        const visit = (node) => {
            if (
                ts.isCallExpression(node) &&
                node.expression.getText(file) === 't' &&
                node.arguments[0] &&
                ts.isStringLiteral(node.arguments[0])
            ) {
                if (!(node.arguments[0].text in catalog))
                    missing.push(`${path}: ${node.arguments[0].text}`);
            }
            ts.forEachChild(node, visit);
        };
        visit(file);
    }
    assert.deepEqual(missing, []);
});

test('homepage dynamic feature headings and FAQ have all locale resources', () => {
    const keys = [
        'Global payments, in your hands.',
        'Protect your account. Never share your password or verification code.',
        'Products',
        'Card management',
        'Getting started',
        'FAQ',
        'Company sign in',
        'Explore your account',
        'Explore products',
        'Card design illustration',
        'Your account. Your cards. One place.',
        'A simpler way to',
        'manage your cards.',
        'Explore the tools available in your account. Product access depends on eligibility and service availability.',
        'Review available card products and apply from your account.',
        'View your available balance and follow each top-up and withdrawal.',
        'Manage your cards from one place, with controls available for each card.',
        'Transaction history',
        'Review card activity and keep track of individual transactions.',
        'Submit your identity materials through your account before applying.',
        'Your card.',
        'A clearer view.',
        'Keep card details, activity and available controls together.',
        'Cardholder information',
        'Review and update cardholder information through the available card controls.',
        'View your cards',
        'Know before',
        'you confirm.',
        'Review the details',
        'Protect your access',
        'Follow the actual status',
        'Fees, limits and product availability are shown in your account. Please review the current information before confirming an operation.',
        'Service availability and processing results are shown in your account. An application is not a guarantee of approval.',
        'begins with you.',
        'Create your account',
        'Complete your information',
        'Apply for your card',
        'Use an invitation code and verify your email or phone number.',
        'Complete identity verification and the requirements shown in your account.',
        'Review the available product, fees and cardholder information before confirming.',
        'Frequently asked',
        'questions.',
        'What do I need to register?',
        'How do I apply for a Mastercard U Card?',
        'Where can I see fees and limits?',
        'When will a top-up arrive?',
        'Timing depends on network confirmations and payment verification. Track the actual status in your account; no fixed arrival time is guaranteed.',
        'Can I manage cards from my phone?',
        'Use the web account on your phone or computer to view cards and the available management controls.',
        'Your account',
        'Help',
        'Terms of service',
        'Complete identity verification and the requirements shown in your account. Review the available product, fees and cardholder information before confirming.',
    ];
    for (const key of keys) assert.ok(catalog[key], key);
});

test('consumer JSX does not reintroduce untranslated visible copy or accessibility labels', () => {
    const raw = [];
    // Currency/network identifiers, deliberately marked mock data, masked PAN and copyright are not translated.
    const allowed =
        /^(?:\$|CVV|USDT|USD|TRON|TRC20|MY|USDT \(|T\.\.\.|TEST|MOCK|© 2026|•••• 1234|08\/29|[\s\d.,+—·:()*/%-]+)$/;
    for (const path of sources) {
        const file = ts.createSourceFile(
            path,
            readFileSync(path, 'utf8'),
            ts.ScriptTarget.Latest,
            true,
            ts.ScriptKind.TSX,
        );
        const check = (text) => {
            const value = text.replace(/\s+/g, ' ').trim();
            if (value && !allowed.test(value)) raw.push(`${path}: ${value}`);
        };
        const visit = (node) => {
            if (ts.isJsxText(node)) check(node.text);
            if (
                ts.isJsxAttribute(node) &&
                ['aria-label', 'title', 'placeholder', 'alt', 'description', 'label'].includes(
                    node.name.text,
                ) &&
                node.initializer &&
                ts.isStringLiteral(node.initializer)
            )
                check(node.initializer.text);
            ts.forEachChild(node, visit);
        };
        visit(file);
    }
    assert.deepEqual(raw, []);
});

test('all actual wallet activity keys translate and preserve exact financial data', () => {
    const { walletActivityItems } = loadTs('resources/js/lib/wallet-activity.ts');
    const events = [
        'WALLET_TOPUP_CREDIT',
        'SECURITY_DEPOSIT_FUND',
        'WITHDRAWAL_HOLD',
        'WITHDRAWAL_RELEASE',
        'WITHDRAWAL_SETTLE',
        'CARD_ISSUE_FEE_HOLD',
        'CARD_INITIAL_LOAD_HOLD',
        'CARD_ISSUE_FEE_RELEASE',
        'CARD_INITIAL_LOAD_RELEASE',
        'CARD_ISSUE_FEE_SETTLE',
        'CARD_INITIAL_LOAD_SETTLE',
        'FUTURE_EVENT',
    ];
    const items = walletActivityItems(
        events.map((eventType) => ({
            id: eventType,
            eventType,
            asset: 'USDT',
            amount: '-123456789012.12345678',
            postedAt: '2026-09-10T12:26:10Z',
        })),
    );
    for (const item of items) {
        assert.ok(item.title in catalog, item.title);
        assert.equal(item.amount, '-123456789012.12345678');
        assert.equal(item.asset, 'USDT');
        for (const locale of locales.slice(1))
            assert.notEqual(i18n.clientI18n.t(item.title, { lng: locale }), item.title);
    }
});

test('fixed tenant articles translate all titles without interpreting authored text as HTML', () => {
    const { tenantArticles } = loadTs('resources/js/lib/tenant-articles.ts');
    assert.deepEqual(
        tenantArticles.map((item) => item.key),
        ['terms', 'privacy', 'account-closure'],
    );
    for (const article of tenantArticles) {
        assert.ok(article.title in catalog);
        for (const locale of locales.slice(1))
            assert.notEqual(i18n.clientI18n.t(article.title, { lng: locale }), article.title);
    }
    const content = readFileSync('resources/js/pages/user/AboutArticle.tsx', 'utf8');
    assert.match(content, /\{article\.body\}/);
    assert.doesNotMatch(content, /dangerouslySetInnerHTML|innerHTML|\beval\(/);
    assert.match(readFileSync('resources/js/pages/user/AccountSettings.tsx', 'utf8'), /href="\/about"/);
});

test('all card transaction labels translate and aggregation preserves exact values and safe ownership', () => {
    const lib = loadTs('resources/js/lib/card-transactions.ts', {
        './exact-amount': loadTs('resources/js/lib/exact-amount.ts'),
    });
    for (const key of [
        ...Object.values(lib.transactionTitles),
        ...Object.values(lib.transactionStates),
    ])
        assert.ok(key in catalog, key);
    const row = {
        id: 'a'.repeat(64),
        cardId: 'owned-a',
        last4: '1234',
        amount: '123456789012.12345678',
        currency: 'USD',
        type: 'purchase',
        state: 'pending',
        displayAt: '2026-09-11T10:00:00+00:00', timeKind: 'recorded',
        merchant: 'A shop',
    };
    assert.equal(
        lib.transactionPage({ items: [row], page: 1, hasMore: true }, 'owned-a', 1).items[0].amount,
        row.amount,
    );
    assert.throws(() =>
        lib.transactionPage({ items: [row], page: 1, hasMore: false }, 'foreign', 1),
    );
    assert.throws(() =>
        lib.transactionPage(
            { items: [{ ...row, amount: 123 }], page: 1, hasMore: false },
            'owned-a',
            1,
        ),
    );
    const other = {
        ...row,
        id: 'b'.repeat(64),
        cardId: 'owned-b',
        last4: '5678',
        displayAt: '2026-09-11T11:00:00+00:00',
    };
    const merged = lib.mergeCardTransactions([row, other], [{ ...row, state: 'completed' }]);
    assert.deepEqual(
        merged.map((item) => item.cardId),
        ['owned-b', 'owned-a'],
    );
    assert.equal(merged[1].state, 'completed');
    assert.equal(merged[1].amount, row.amount);
    for (const [amount, expected] of [
        ['123456789012.12345678', '123456789012.12'],
        ['-12.34000000', '-12.34'],
        ['0.00000001', '0.00'],
        ['20.00000000', '20.00'],
    ])
        assert.equal(lib.transactionAmount(amount), expected);
});

test('local simulated transactions promote the card tail and type without hiding real merchants', () => {
    const lib = loadTs('resources/js/lib/card-transactions.ts', {
        './exact-amount': loadTs('resources/js/lib/exact-amount.ts'),
    });
    const history = {
        items: [{ id: 'mock-row', last4: '1234', merchant: 'TEST / LOCAL MOCK', type: 'transfer_in', state: 'completed', amount: '35.00000000', currency: 'USD', displayAt: '2026-09-13T14:54:32+00:00', timeKind: 'recorded' }],
        failed: 0, loading: false, hasMore: false,
    };
    const { UserCardTransactions } = loadTs('resources/js/components/user/UserCardTransactions.tsx', {
        '@/lib/system-money': loadTs('resources/js/lib/system-money.ts', { './exact-amount': loadTs('resources/js/lib/exact-amount.ts') }),
        '@/i18n': { ...i18n, useClientTranslation: () => undefined },
        '@/components/ui/button': { Button: 'button' },
        '@/hooks/useCardTransactions': { useCardTransactions: () => history },
        '@/lib/card-transactions': lib,
    });
    const { renderToStaticMarkup } = require('react-dom/server');
    const previousLocale = i18n.clientI18n.language;
    try {
        void i18n.clientI18n.changeLanguage('zh-CN');
        const render = () => renderToStaticMarkup(UserCardTransactions({ cardIds: ['owned-card'], available: true }));
        const markup = render();
        assert.doesNotMatch(markup, /TEST \/ LOCAL MOCK/);
        assert.doesNotMatch(markup, /已加载记录按交易时间|Loaded records are sorted/);
        assert.match(markup, /font-medium">尾号 1234 · 卡片充值<\/p>/);
        assert.equal((markup.match(/尾号 1234/g) ?? []).length, 1);
        assert.match(markup, /\$35\.00/);
        assert.doesNotMatch(markup, /记录时间|已完成/);
        assert.match(markup, /<time dateTime="2026-09-13T14:54:32\+00:00"/);
        assert.doesNotMatch(markup, /发卡方|卡商|通道方/);
        history.items[0].merchant = 'A real shop';
        assert.match(render(), /font-medium">A real shop<\/p>/);
    } finally {
        void i18n.clientI18n.changeLanguage(previousLocale);
    }
});

test('default card display name follows the selected language and keeps U unchanged', () => {
    const { cardDisplayName } = loadTs('resources/js/lib/card-display-name.ts', { '@/i18n': i18n });
    const previousLocale = i18n.clientI18n.language;
    try {
        for (const [locale, expected] of Object.entries({
            en: 'U Card',
            'zh-CN': 'U 卡',
            ms: 'Kad U',
            es: 'Tarjeta U',
        })) {
            void i18n.clientI18n.changeLanguage(locale);
            for (const name of ['Mille Card', 'U Card']) {
                assert.equal(cardDisplayName(name), expected);
                assert.ok(cardDisplayName(name).includes('U'));
            }
            for (const name of ['Tenant Gold', 'Mille Card Plus', 'My U Card'])
                assert.equal(cardDisplayName(name), name);
        }
    } finally {
        void i18n.clientI18n.changeLanguage(previousLocale);
    }
    const page = readFileSync('resources/js/pages/user/Cards.tsx', 'utf8');
    for (const field of ['option.name', 'product.name', 'card.productName'])
        assert.ok(page.includes(`cardDisplayName(${field})`), field);
});

test('language switching translates real activity and card keys without changing decimal strings', () => {
    globalThis.document = { documentElement: { lang: '' } };
    for (const locale of locales) {
        i18n.configureClientLocale(locale, 'Asia/Kuala_Lumpur');
        assert.equal(document.documentElement.lang, locale);
        for (const key of [
            'Completed',
            'Available cards',
            'Opening fee',
            'Initial card funding completed',
            'Card opening completed',
            'Initial card balance',
            'Open card',
        ]) {
            assert.equal(i18n.t(key), i18n.clientResources[locale].translation[key]);
            if (locale !== 'en') assert.notEqual(i18n.t(key), key);
        }
        const key =
            'An opening fee of {{fee}} USDT and initial balance of {{amount}} USDT will be reserved separately while your card is created. Do not create another request while it is pending.';
        const result = i18n.t(key, { fee: '5.00', amount: '20.12345678' });
        assert.ok(result.includes('5.00') && result.includes('20.12345678'));
        assert.ok(result.includes('USDT') && !result.includes('{{'));
        assert.equal(
            i18n.dateTime('2026-09-10T12:26:10Z'),
            new Intl.DateTimeFormat(locale, {
                timeZone: 'Asia/Kuala_Lumpur',
                dateStyle: 'medium',
                timeStyle: 'short',
            }).format(new Date('2026-09-10T12:26:10Z')),
        );
    }
    i18n.configureClientLocale('zh-CN', 'UTC');
    assert.equal(
        i18n.errorMessage('The password field must be at least 12 characters.'),
        i18n.t('Use at least {{min}} characters.', { min: '12' }),
    );
    assert.equal(
        i18n.errorMessage('The email field must be a valid email address.'),
        i18n.t('Enter a valid email address.'),
    );
    assert.equal(
        i18n.errorMessage('untrusted-provider-secret'),
        i18n.t('Unable to complete this request. Check your information and current status.'),
    );
    assert.equal(i18n.dateTime('invalid'), '—');
    delete globalThis.document;
});


test('card reload preview adds exact principal and does not invent unavailable balances', () => {
    const { cardReloadBalance } = loadTs('resources/js/lib/exact-amount.ts');
    assert.equal(cardReloadBalance('30', '20.00000000'), '50.00000000');
    assert.equal(cardReloadBalance('0.20', '0.10000001'), '0.30000001');
    assert.equal(cardReloadBalance('30', '-35.00000000'), '-5.00000000');
    assert.equal(cardReloadBalance('0.01', '999999999999.99999999'), '1000000000000.00999999');
    assert.equal(cardReloadBalance('30', null), null);
    for (const amount of ['', '0', '-1', '1e2', '0.001', 'abc']) {
        assert.equal(cardReloadBalance(amount, '20.00000000'), null);
    }
});

test('system money uses an exact dollar prefix including negative and large decimal amounts', () => {
    const { systemMoney } = loadTs('resources/js/lib/system-money.ts', { './exact-amount': loadTs('resources/js/lib/exact-amount.ts') });
    assert.equal(systemMoney('21.00000000'), '$21.00');
    assert.equal(systemMoney('-21.25000000'), '-$21.25');
    assert.equal(systemMoney('0.00000000'), '$0.00');
    assert.equal(systemMoney('999999999999.99000000'), '$999,999,999,999.99');
});

test('consumer copy hides infrastructure sources in all four locales without dropping financial warnings', () => {
    const { clientCopyAliases } = loadTs('resources/js/i18n/client-polish-catalog.ts');
    for (const locale of locales) {
        for (const key of Object.keys(clientCopyAliases)) {
            const copy = i18n.clientI18n.t(key, { lng: locale });
            assert.doesNotMatch(copy, /发卡方|通道方|卡商|服务商|issuer|provider|pengeluar|penyedia|emisor|proveedor/i);
        }
    }
    assert.match(i18n.clientI18n.t('Issuer fee', { lng: 'zh-CN' }), /手续费/);
    assert.match(i18n.clientI18n.t('Card cancellation is permanent. Remaining card funds return only after issuer confirmation, minus any issuer fees. This does not refund your security deposit.', { lng: 'zh-CN' }), /无法恢复.*手续费.*保证金/);
});

test('transaction history only reads saved pages and retries local failures without upstream sync', async () => {
    const lib = loadTs('resources/js/lib/card-transactions.ts', { './exact-amount': loadTs('resources/js/lib/exact-amount.ts') });
    let state, cleanup;
    let failSecondPage = true;
    const calls = [];
    const row = { id: 'a'.repeat(64), cardId: 'card-a', last4: '1234', amount: '1.23000000', currency: 'USD', type: 'purchase', state: 'completed', displayAt: '2026-09-15T00:00:00+00:00', timeKind: 'recorded', merchant: null };
    const originalFetch = globalThis.fetch;
    globalThis.fetch = async (url, options) => {
        calls.push({ url, options });
        assert.equal(options.method, 'GET');
        assert.doesNotMatch(url, /sync/);
        const page = Number(new URL(url, 'http://localhost').searchParams.get('page'));
        if (page === 2 && failSecondPage) return { ok: false };
        return { ok: true, json: async () => ({ page, hasMore: page === 1, items: [{ ...row, id: page === 2 ? 'b'.repeat(64) : row.id }] }) };
    };
    try {
        const { useCardTransactions } = loadTs('resources/js/hooks/useCardTransactions.ts', {
            react: { useState: () => [undefined, (next) => { state = next; }], useRef: (current) => ({ current }), useEffect: (run) => { cleanup = run(); } },
            '@/lib/card-transactions': lib,
        });
        const hook = useCardTransactions(['card-a']);
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(state.failed, 0);
        assert.equal(state.items.length, 1);
        hook.loadMore();
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(state.failed, 1);
        assert.equal(state.items.length, 1);
        failSecondPage = false;
        hook.retry();
        await new Promise((resolve) => setImmediate(resolve));
        assert.equal(state.failed, 0);
        assert.equal(state.items.length, 2);
        assert.equal(state.hasMore, false);
        assert.deepEqual(calls.map(call => call.url), ['/cards/card-a/transactions?page=1', '/cards/card-a/transactions?page=2', '/cards/card-a/transactions?page=2']);
    } finally { cleanup?.(); globalThis.fetch = originalFetch; }
});

test('system transaction times cross company midnight without guessing unzoned times', () => {
    const previousDocument = globalThis.document;
    const previousLocale = i18n.clientI18n.language;
    globalThis.document = { documentElement: { lang: 'en' } };
    try {
        i18n.configureClientLocale('zh-CN', 'Asia/Kuala_Lumpur');
        assert.match(i18n.dateTime('2026-09-15T15:59:59+00:00'), /9月15日/);
        assert.match(i18n.dateTime('2026-09-15T16:00:00+00:00'), /9月16日/);
        assert.equal(i18n.dateTime('2026-09-15T16:00:00+00:00'), i18n.dateTime('2026-09-16T00:00:00+08:00'));
    } finally {
        i18n.configureClientLocale(previousLocale, 'UTC');
        globalThis.document = previousDocument;
    }
});

test('account verification navigation renders every state in all client languages', () => {
    const { createElement } = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const { AccountVerificationLink } = loadTs('resources/js/components/user/AccountVerificationLink.tsx', {
        '@/i18n': { ...i18n, useClientTranslation: () => undefined },
        '@inertiajs/react': { Link: 'a' },
    });
    const previousLocale = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            for (const [status, key, action] of [
                ['NOT_SUBMITTED', 'Not verified', 'Go to verification'],
                ['PENDING', 'Under review', 'View details'],
                ['APPROVED', 'Verified', 'View details'],
                ['RESUBMISSION_REQUIRED', 'Action required', 'Go to verification'],
                ['REJECTED', 'Verification could not be approved', 'View details'],
            ]) {
                const markup = renderToStaticMarkup(createElement(AccountVerificationLink, { status, fromSecurity: true }));
                assert.ok(markup.includes(i18n.t(key)), `${locale}: ${status}`);
                assert.ok(markup.includes(i18n.t(action)), `${locale}: ${action}`);
                assert.ok(markup.includes('href="/kyc?from=account-security"'));
                assert.ok(!markup.includes(status));
            }
            assert.ok(renderToStaticMarkup(createElement(AccountVerificationLink, { status: 'APPROVED' })).includes('href="/kyc"'));
            for (const key of ['Settings', 'Common features', 'Go to verification']) {
                assert.ok(i18n.clientResources[locale].translation[key]);
            }
        }
    } finally {
        void i18n.clientI18n.changeLanguage(previousLocale);
    }
});

test('promotion reports keep income, transfers and member contributions distinct across four languages', () => {
    const React = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const money = loadTs('resources/js/lib/system-money.ts', { './exact-amount': loadTs('resources/js/lib/exact-amount.ts') });
    const translation = { ...i18n, useClientTranslation: () => ({ i18n: i18n.clientI18n }) };
    const inertia = { Head: () => null, Link: 'a', router: { get: () => {} }, usePage: () => ({ props: { errors: {} } }),
        useForm: (data) => ({ data, errors: {}, processing: false, post: () => {}, setData: () => {}, reset: () => {}, clearErrors: () => {} }) };
    const overrides = {
        '@/i18n': translation, '@/lib/system-money': money, '@inertiajs/react': inertia,
        '@/layouts/UserLayout': { UserLayout: ({ children }) => React.createElement('main', null, children) },
        '@/components/user/UserPageHeader': { UserPageHeader: ({ title }) => React.createElement('h1', null, title) },
        '@/components/ui/button': { Button: 'button' }, '@/components/ui/input': { Input: 'input' },
        '@/components/ui/select': Object.fromEntries(['Select', 'SelectTrigger', 'SelectValue', 'SelectContent', 'SelectItem'].map(key => [key, 'div'])),
        '@/components/user/FinancialConfirmation': { FinancialConfirmation: ({ title, disabled }) => React.createElement('button', { disabled }, title) },
        '../../../css/promotion.css': {},
    };
    overrides['@/components/user/PromotionDateFilter'] = loadTs('resources/js/components/user/PromotionDateFilter.tsx', overrides);
    overrides['@/lib/exact-amount'] = loadTs('resources/js/lib/exact-amount.ts');
    overrides['@/lib/paid-promotion'] = loadTs('resources/js/lib/paid-promotion.ts', overrides);
    overrides['@/components/user/PaidPromotionSummary'] = loadTs('resources/js/components/user/PaidPromotionSummary.tsx', overrides);
    const Promotion = loadTs('resources/js/pages/user/Promotion.tsx', overrides).default;
    overrides['@/lib/promotion-report'] = loadTs('resources/js/lib/promotion-report.ts', overrides);
    overrides['@/components/ui/dialog'] = { Dialog: ({children}) => children, DialogTrigger: ({children}) => children, DialogContent: () => null, DialogTitle: 'h2', DialogDescription: 'p' };
    overrides['@/components/user/PromotionReportControls'] = loadTs('resources/js/components/user/PromotionReportControls.tsx', overrides);
    const Report = loadTs('resources/js/pages/user/PromotionReport.tsx', overrides).default;
    const Commissions = loadTs('resources/js/pages/user/PromotionCommissions.tsx', overrides).default;
    const p = {
        paid: {rank: 6, percent: 80, reward: '100', cycle: null, totals: {ANNUAL:'17000',ACTIVATION:'1000'}, legacy:'0', directPeople:9,indirectPeople:5,tables:{ANNUAL:[],ACTIVATION:[]}},
        invitationCode: '523613', levelName: 'Long level '.repeat(8), availableCommission: '16880.00000000', myCommission: '18000.00000000',
        supported: true, canTransfer: true, date: '2026-09-13', timezone: 'Asia/Kuala_Lumpur',
        totals: { invited: 500, activated: 300, deposits: '90000.00000000', commission: '36000.00000000' },
        daily: { invited: 0, activated: 0, deposits: '0.00000000', commission: '0.00000000' },
        direct: [{ id: 'member', accountId: '202607303070', levelId: null, joinedAt: '2026-07-30T09:03:00Z', depositAmount: '0.00000000', myCommission: '0.00000000' }],
        assignableLevels: [], canAssign: false, directTotal: 1, filters: { accountId: '', funding: 'all' }, directPage: 1, page: 1, hasMore: false, hasMoreDirect: false, details: [],
    };
    const period = { dateFrom: null, dateTo: null, today: '2026-09-16', timezone: p.timezone, presets: {1:'2026-09-16',7:'2026-09-10',30:'2026-08-18'} };
    const totals = { total: '123456789012.12000001', annual: '123456789012.12000000', activation: '0.00000001', legacy: '0' };
    const history = { ...period, filters: {}, tab: 'income', totals, page: 1, hasMore: false, items: [
        { id: 'annual', kind: 'annual', amount: '123456789012.12000000', sourceAccountId: '202609134788', sourceRank: 1, beneficiaryRank: 6, relation: 'direct', sourceAmount: '154320986265.15', rate: '80', standard: 80, covered: 0, occurredAt: '2026-09-13T09:49:00Z' },
        { id: 'legacy', kind: 'legacy', amount: '0.00000001', sourceAccountId: '202609134789', sourceRank: null, beneficiaryRank: null, relation: 'unknown', occurredAt: '2026-09-13T09:49:00Z' },
    ] };
    const previousLocale = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            const home = renderToStaticMarkup(React.createElement(Promotion, { promotion: p }));
            assert.ok(home.includes('18,000.00'));
            assert.ok(!home.includes('16,880.00 USDT'));
            assert.ok(home.includes(i18n.t('Commission is automatically credited to your USDT balance.')));
            assert.ok(home.includes('id="team-summary"'));
            assert.ok(!home.includes('href="/promotion/team"'));
            const daily = renderToStaticMarkup(React.createElement(Report, { section: 'daily', report: {
                ...period, dateFrom: '2026-09-16', dateTo: '2026-09-16', filters: {}, totals, counts: { invited: 1, funded: 2, orders: 3 }, items: [], page: 1, hasMore: false,
            } }));
            assert.ok(daily.includes('2026-09-16'));
            assert.ok(daily.includes(i18n.t('Annual fee orders')));
            const direct = renderToStaticMarkup(React.createElement(Report, { section: 'direct', report: {
                ...period, filters: {}, total: 1, page: 1, hasMore: false, items: [{ id: 'member', accountId: '202609134788', rank: 0, endsAt: null, depositAmount: '0', joinedAt: '2026-09-13T09:49:00Z', totals }],
            } }));
            assert.ok(direct.includes(i18n.t('Deposit not funded')));
            assert.ok(direct.includes('/promotion/commissions?account_id=202609134788'));
            assert.ok(direct.includes(i18n.t('Annual fee commission')));
            const rows = renderToStaticMarkup(React.createElement(Commissions, { history }));
            assert.ok(rows.includes('+123,456,789,012.12 USDT'));
            assert.ok(rows.includes('&lt;0.01 USDT'));
            assert.ok(rows.includes('0.00000001 USDT'));
            assert.ok(rows.includes(i18n.t('Historical record · not recorded')));
            assert.ok(rows.includes('80 %'));
            assert.equal((rows.match(/202609134788/g) ?? []).length, 2);
            assert.ok(!rows.includes('Asia/Kuala_Lumpur'));
            assert.ok(!rows.includes(i18n.t('Transfers to balance')));

        }
    } finally { void i18n.clientI18n.changeLanguage(previousLocale); }
});

test('promotion display totals preserve eight-decimal rewards without floating point', () => {
    const { commissionSum, promotionMoney, membershipAction } = loadTs('resources/js/lib/paid-promotion.ts', { '@/i18n': { t: key => key }, '@/lib/exact-amount': loadTs('resources/js/lib/exact-amount.ts') });
    assert.equal(commissionSum('1600.00000001', '190'), '1790.00000001');
    assert.equal(commissionSum('999999999999.99999999', '0.00000001'), '1000000000000.00000000');
    assert.equal(promotionMoney('0.00000001'), '0.00000001 USDT');
    assert.equal(promotionMoney('16880.00000000'), '16,880.00 USDT');
    assert.equal(membershipAction({rank:0,membershipStatus:'NONE'}), 'Apply for promotion membership');
    assert.equal(membershipAction({rank:0,membershipStatus:'EXPIRED'}), 'Renew promotion membership');
    assert.equal(membershipAction({rank:6,membershipStatus:'ACTIVE'}), 'Upgrade promotion level');
    assert.equal(membershipAction({rank:8,membershipStatus:'ACTIVE'}), 'View level benefits');
});

test('compact promotion table amounts do not hide small rewards or lose exact expanded values', () => {
    const { promotionTableAmount, promotionMoney } = loadTs('resources/js/lib/paid-promotion.ts', { '@/i18n': { t: key => key }, '@/lib/exact-amount': loadTs('resources/js/lib/exact-amount.ts') });
    assert.equal(promotionTableAmount('0'), '0.00');
    assert.equal(promotionTableAmount('0.00000001'), '<0.01');
    assert.equal(promotionTableAmount('0.00999999'), '<0.01');
    assert.equal(promotionTableAmount('1600.125'), '1,600.13');
    assert.equal(promotionTableAmount('999999999999.99'), '999,999,999,999.99');
    assert.equal(promotionMoney('0.00000001'), '0.00000001 USDT');
});

test('saved consumer language survives old navigation snapshots without crossing user or company scope', () => {
    const { consumerLocaleScope, rememberConfirmedLocale, resolveConfirmedLocale } = loadTs('resources/js/i18n/confirmed-locale.ts');
    const scope = consumerLocaleScope('tenant-a', 'user-a');
    rememberConfirmedLocale(scope, 'zh-CN');
    assert.equal(resolveConfirmedLocale(scope, 'en', ['en', 'zh-CN']), 'zh-CN');
    assert.equal(resolveConfirmedLocale(consumerLocaleScope('tenant-b', 'user-a'), 'en', ['en', 'zh-CN']), 'en');
    assert.equal(resolveConfirmedLocale(consumerLocaleScope('tenant-a', 'user-b'), 'en', ['en', 'zh-CN']), 'en');
    assert.equal(resolveConfirmedLocale(scope, 'en', ['en']), 'en');
});

test('multi-asset withdrawal percentages use exact units and round fees up', () => {
    const { withdrawalPercentageFee: fee } = loadTs('resources/js/lib/exact-amount.ts');
    assert.equal(fee('100', '1', 'USDT'), '1.000000');
    assert.equal(fee('0.000101', '1', 'USDC'), '0.000002');
    assert.equal(fee('0.000000000000000101', '1', 'ETH'), '0.000000000000000002');
    assert.equal(fee('0.00000101', '1', 'BTC'), '0.00000002');
    assert.equal(fee('1', '0.00000000', 'BTC'), '0.00000000');
    assert.equal(fee('0.000001', '1', 'USDC'), null);
    assert.equal(fee('1', '100', 'ETH'), null);
    assert.equal(fee('1', '-1', 'ETH'), null);
    assert.equal(fee('1', null, 'BTC'), null);
    assert.equal(fee('1.0000001', '1', 'USDT'), null);
    assert.equal(fee('1e5', '1', 'BTC'), null);
});

test('annual return progress distinguishes automatic, pending and completed states in four languages', () => {
    const React = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const amounts = loadTs('resources/js/lib/exact-amount.ts');
    const overrides = { '@/i18n': i18n, '@/lib/exact-amount': amounts, '@inertiajs/react': { Link: 'a' } };
    overrides['@/lib/paid-promotion'] = loadTs('resources/js/lib/paid-promotion.ts', overrides);
    const { AnnualRebateProgress } = loadTs('resources/js/components/user/AnnualRebateProgress.tsx', overrides);
    const initial = { policy: 'AUTO_FIRST_FUNDING', direct: 34, indirect: 3, target: 100, paid: '1000.00000000', returned: '0', remaining: '1000.00000000', pending: false };
    const previous = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            const render = progress => renderToStaticMarkup(React.createElement(AnnualRebateProgress, { progress }));
            const pending = render(initial);
            assert.ok(pending.includes('aria-valuenow="35.5"'));
            assert.ok(pending.includes('64.5'));
            assert.ok(pending.includes('1000 USDT'));
            assert.ok(!pending.includes('<a'));
            assert.ok(!pending.includes('{{'));
            const processing = render({ ...initial, pending: true });
            assert.ok(processing.includes(i18n.t('Annual fee return is processing.')));
            const preview = renderToStaticMarkup(React.createElement(AnnualRebateProgress, { progress: { ...initial, direct: 100 }, preview: true }));
            assert.ok(preview.includes(i18n.t('Annual fee return progress after payment')));
            assert.ok(preview.includes(i18n.t('After successful payment, {{amount}} USDT will be returned automatically.', {amount:'1000'})));
            assert.ok(!preview.includes(i18n.t('Annual fee return is processing.')));
            const complete = render({ ...initial, direct: 101, remaining: '0', returned: '1000' });
            assert.ok(complete.includes('aria-valuenow="100"'));
            assert.ok(complete.includes(i18n.t('Annual fee returned: {{amount}} USDT', { amount: '1000' })));

        }
    } finally { void i18n.clientI18n.changeLanguage(previous); }
});

test('membership page omits rebate progress and history in every locale', () => {
    const React = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const overrides = {
        '@/i18n': { ...i18n, useClientTranslation: () => ({ i18n: i18n.clientI18n }) },
        '@/lib/exact-amount': loadTs('resources/js/lib/exact-amount.ts'),
        '@inertiajs/react': { Head: () => null, Link: 'a', useForm: data => ({ data, errors: {}, processing: false }) },
        '@/layouts/UserLayout': { UserLayout: ({ children }) => React.createElement('main', null, children) },
        '@/components/user/UserPageHeader': { UserPageHeader: ({ title }) => React.createElement('h1', null, title) },
        '@/components/ui/button': { Button: 'button' },
        '@/components/ui/dialog': { Dialog: ({ children }) => children, DialogContent: () => null, DialogHeader: 'div', DialogTitle: 'h2', DialogDescription: 'p' },
        '@/components/user/FinancialConfirmation': { FinancialConfirmation: ({ url, title }) => React.createElement('button', { 'data-action': url }, title) },
    };
    overrides['@/lib/paid-promotion'] = loadTs('resources/js/lib/paid-promotion.ts', overrides);
    overrides['@/components/user/AnnualRebateProgress'] = loadTs('resources/js/components/user/AnnualRebateProgress.tsx', overrides);
    overrides['@/components/user/IdentityVerificationDialog'] = loadTs('resources/js/components/user/IdentityVerificationDialog.tsx', overrides);
    const Page = loadTs('resources/js/pages/user/PaidPromotion.tsx', overrides).default;
    const paid = {
        activation: { agent: true, qualified: true, refundPending: false }, paymentAccess: { verified: true, walletActive: true, canCreateWallet: false },
        rank: 1, membershipStatus: 'ACTIVE', percent: 30, reward: '50', availableBalance: '2000',
        cycle: { tariff: '1000', endsAt: '2027-09-17T01:00:00Z', rebatePolicy: 'AUTO_FIRST_FUNDING' },
        progress: { policy: 'AUTO_FIRST_FUNDING', direct: 35, indirect: 0, target: 100, remaining: '1000', paid: '1000', returned: '0', pending: false },
        levels: [{ id: 'rank2', rank: 2, enabled: true, fee: '2000', percent: 40, reward: '60', target: 135 }],
        claims: [], claimsPage: 1, hasMoreClaims: false, pending: false,
    };
    const previous = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            for (const pending of [false, true]) {
                const html = renderToStaticMarkup(React.createElement(Page, { paid: { ...paid, pending, progress: { ...paid.progress, pending } }, quote: null }));
                assert.ok(!html.includes(i18n.t('Annual fee return progress')));
                assert.ok(!html.includes(i18n.t('Fee rebate history')));
                assert.ok(!html.includes('/promotion/rebates'));
                assert.ok(!html.includes('MANUAL'));
                assert.ok(!html.includes('请在到期前申请'));
                assert.ok(html.includes(i18n.t('Each account counts once on its first member deposit or agent purchase. Annual fees are returned automatically when the target is reached.')));
                if (pending) assert.ok(html.includes(i18n.t('Annual fee return is processing. Try upgrading shortly.')));
                const inactive = { ...paid, rank: 0, cycle: null, membershipStatus: 'NONE', pending: false,
                    activation: { qualified: false, agent: false, ordinaryAvailable: true, depositRequired: '300', refundPending: false } };
                const choice = renderToStaticMarkup(React.createElement(Page, { paid: inactive, quote: null }));
                assert.ok(choice.includes('value="ordinary"'));
                assert.ok(choice.includes(i18n.t('No annual fee')));
                assert.ok(choice.includes(i18n.t('Activate your account')));
                assert.ok(!html.includes('value="ordinary"'));
                const quote = { id: 'quote', rank: 2, amount: '700', depositApplied: '300', settlementTotal: '1000', previousTariff: '1000', tariff: '2000', status: 'QUOTED', expiresAt: '2099-01-01T00:00:00Z', cycleId: 'cycle' };
                const review = renderToStaticMarkup(React.createElement(Page, { paid, quote }));
                for (const key of ['Deposit converted to annual fee', 'Wallet payment', 'Annual fee settlement total']) assert.ok(review.includes(i18n.t(key)));

            }
        }
    } finally { void i18n.clientI18n.changeLanguage(previous); }
});


test('assets show the activation entry above accounts only when qualification is missing in four locales', () => {
    const React = require('react');
    const { renderToStaticMarkup } = require('react-dom/server');
    const overrides = {
        '@/i18n': i18n,
        '@/lib/exact-amount': loadTs('resources/js/lib/exact-amount.ts'),
        '@inertiajs/react': { Link: 'a', usePage: () => ({ props: {auth: { user: {id: 'fixture'} }}}) },
    };
    overrides['@/components/user/GrowthCampaign'] = loadTs('resources/js/components/user/GrowthCampaign.tsx', { ...overrides, '@/i18n': { ...i18n, useClientTranslation: () => ({ i18n: i18n.clientI18n }) } });
    const { AssetCenter } = loadTs('resources/js/components/user/AssetCenter.tsx', overrides);
    const overview = { activation: { qualified: false }, cumulativeCommission: '12.00', estimate: '300', updatedAt: null,
        assets: [{asset: 'USDT', available: '300', held: '0', deposit: '0', exchange: false, rails: [], activity: []}] };
    const previous = i18n.clientI18n.language;
    try {
        for (const locale of locales) {
            void i18n.clientI18n.changeLanguage(locale);
            const pending = renderToStaticMarkup(React.createElement(AssetCenter, {overview}));
            const active = renderToStaticMarkup(React.createElement(AssetCenter, {overview: {...overview, activation: {qualified: true}}}));
            assert.ok(pending.includes('href="/promotion/membership"'));
            assert.ok(pending.indexOf(i18n.t('Account pending activation')) < pending.indexOf('id="asset-accounts"'));
            assert.ok(!active.includes(i18n.t('Account pending activation')));
            assert.ok(!active.includes('href="/promotion/invitations"'));
            assert.ok(!active.includes(i18n.t('Wealth management')));
        }
    } finally { void i18n.clientI18n.changeLanguage(previous); }
});
