<script setup lang="ts">
import { computed, ref } from 'vue';
import PageShell from '../components/PageShell.vue';
import ReportPagination from '../components/ReportPagination.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { fullMoney } from '../lib/promotion-report';
import type { StockReport } from '../lib/stock';
const props = defineProps<{ page: { report: StockReport } }>(),
    r = computed(() => props.page.report),
    expanded = ref<Record<number, boolean>>({});
const legacyLines = [
    ['annual', 'Total annual fees paid', '+'],
    ['deposits', 'Ordinary member deposit balances', '+'],
    ['fees', 'Withdrawal fee income', '+'],
    ['activation', 'Activation commissions paid', '−'],
    ['annualCommission', 'Annual fee commissions paid', '−'],
    ['rebates', 'Annual fees returned', '−'],
    ['reimbursements', 'Reimbursed expenses', '−'],
];
const lines = computed(() =>
    r.value.version === 'partner'
        ? [
              ['inflow', 'Team total deposits', '+'],
              ['outflow', 'Team total withdrawals', '−'],
          ]
        : legacyLines,
);
const trends: Record<string, string> = {
    activation: 'Activation commissions paid',
    deposits: 'First deposit activations',
    annual: 'Annual fee income',
};
function value(v: string | null | undefined) {
    return v == null ? t('Incomplete valuation') : fullMoney(v);
}
function average(n: string) {
    return n === 'today' ? t('Today') : t('Previous {{days}} days average', { days: n });
}
const more = computed(() =>
    [r.value.journal, r.value.unvalued, r.value.risks.active, r.value.risks.expired].some(
        (p) => p.hasMore,
    ),
);
</script>
<template>
    <PageShell :title="t('Stock data')" back="/promotion/daily" active="account" white
        ><view class="stock"
            ><text class="muted small"
                >{{ t('Updated') }}: {{ dateTime(r.updatedAt) }} · {{ r.timezone }} · USDT</text
            ><text class="heading">{{
                t(r.version === 'partner' ? 'Partner version' : 'Standard version')
            }}</text
            ><view class="hero"
                ><text>{{ t('Current total stock') }}</text
                ><text class="total">{{ value(r.stock) }}</text></view
            ><text v-if="r.missingRates > 0" class="warning"
                >{{ t('Rates pending') }}: {{ r.missingRates }}.
                {{
                    t(
                        r.version === 'partner'
                            ? 'Current exchange rates are unavailable. USDT valuation is incomplete.'
                            : 'Stock and reference share cannot be fully calculated until fee rates are completed.',
                    )
                }}</text
            ><text v-if="r.negative" class="warning">{{ t('Total stock is negative.') }}</text
            ><view class="panel"
                ><text class="heading">{{ t('Stock composition') }}</text
                ><view v-for="[key, label, sign] in lines" :key="key" class="row"
                    ><text>{{ sign }} {{ t(label) }}</text
                    ><text>{{
                        key === 'fees' && r.missingRates
                            ? t('Incomplete valuation')
                            : value(r.totals[key])
                    }}</text></view
                ><text v-if="r.version === 'partner'" class="muted small">{{
                    t('Partner account commissions are excluded from stock.')
                }}</text></view
            ><view v-if="r.cashFlow" class="panel">
                <text class="muted small">{{
                    t(
                        'Partner stock = descendant deposits − descendant gross withdrawals. Your own deposits and withdrawals are excluded. Commissions, annual fees, deposits held and internal transfers are not stock components.',
                    )
                }}</text>
                <text class="muted small">{{
                    t(
                        'All currencies use the same current USDT rates. The valuation changes with market prices.',
                    )
                }}</text>
                <text v-if="r.cashFlow.rateObservedAt" class="muted small"
                    >{{ t('Exchange rate time') }}: {{ dateTime(r.cashFlow.rateObservedAt) }}</text
                >
                <view v-for="asset in r.cashFlow.assets" :key="asset.asset" class="detail">
                    <text class="strong">{{ asset.asset }}</text>
                    <text
                        >{{ t('Team total deposits') }}: {{ asset.inflow }} {{ asset.asset }}</text
                    >
                    <text
                        >{{ t('Team total withdrawals') }}: {{ asset.outflow }}
                        {{ asset.asset }}</text
                    >
                    <text>1 {{ asset.asset }} = {{ asset.rate ?? '—' }} USDT</text>
                </view> </view
            ><view v-if="r.accountBalance" class="panel"
                ><text class="heading">{{ t('Account balance reconciliation') }}</text
                ><view
                    v-for="[key, label] in [
                        ['theoretical', 'Theoretical account balance'],
                        ['actual', 'Actual account balance'],
                        ['difference', 'Account balance difference'],
                    ]"
                    :key="key"
                    class="row"
                    ><text>{{ t(label) }}</text
                    ><text>{{
                        value(r.accountBalance[key as 'theoretical' | 'actual' | 'difference'])
                    }}</text></view
                ><text class="muted small">{{
                    t(
                        'Theoretical balance = personal net advances + activation commissions received + annual fee commissions received + net manual commissions − personal net reimbursements. Actual balance is your available USDT wallet balance. Difference = theoretical − actual.',
                    )
                }}</text></view
            ><view class="panel"
                ><text class="heading">{{ t('Team alerts') }}</text
                ><view
                    v-for="[label, amount] in [
                        ['Cumulative advances', value(r.totals.advances)],
                        ['Active agents at 70% return progress', String(r.risks.activeCount)],
                        ['Remaining annual fees to return', value(r.risks.remaining)],
                        [
                            'Expired cycles with pending returns',
                            r.risks.expiredCount + ' · ' + value(r.risks.expiredAmount),
                        ],
                    ]"
                    :key="label"
                    class="row"
                    ><text>{{ t(label) }}</text
                    ><text>{{ amount }}</text></view
                ><view v-for="(group, i) in [r.risks.active, r.risks.expired]" :key="i"
                    ><button class="expand" @click="expanded[i] = !expanded[i]">
                        {{ expanded[i] ? '−' : '+' }}
                        {{ t(i ? 'Pending expired return details' : '70% progress details') }} ({{
                            group.total
                        }})</button
                    ><template v-if="expanded[i]"
                        ><view v-for="row in group.items" :key="row.id" class="detail"
                            ><text class="strong">{{ row.account_id }}</text
                            ><text>{{ t('Progress') }}: {{ row.weighted }} / {{ row.target }}</text
                            ><text
                                >{{ t('Remaining annual fees to return') }}:
                                {{ value(row.remaining) }}</text
                            ><text v-if="i === 1"
                                >{{ t('Pending') }}: {{ value(row.pending) }}</text
                            ></view
                        ></template
                    ></view
                ></view
            ><view v-if="Object.keys(r.trends).length" class="panel"
                ><text class="heading">{{ t('Daily trends') }}</text
                ><text class="muted small">{{
                    t(
                        'Averages cover complete calendar days before today, including zero-activity days, in the company timezone.',
                    )
                }}</text
                ><view v-for="(trend, key) in r.trends" :key="key" class="trend"
                    ><text class="strong">{{ t(trends[key] ?? key) }}</text
                    ><view v-for="n in ['today', '3', '7', '15', '30']" :key="n" class="row"
                        ><text>{{ average(n) }}</text
                        ><text>{{ value(trend[n]) }}</text></view
                    ></view
                ></view
            ><view v-if="r.version === 'partner'" class="panel"
                ><text class="heading">{{ t('Cooperation journal') }} ({{ r.journal.total }})</text
                ><text class="muted small">{{
                    t(
                        'Offline cooperation records only; these entries do not represent system payments.',
                    )
                }}</text
                ><view v-for="row in r.journal.items" :key="row.id" class="detail"
                    ><text class="strong"
                        >{{ row.account_id }} ·
                        {{ t(row.kind === 'ADVANCE' ? 'Advance' : 'Reimbursement') }}
                        {{ row.reverses_id ? '· ' + t('Reversal') : '' }}</text
                    ><text
                        >{{ row.reverses_id ? '−' : '' }}{{ value(row.amount) }} ·
                        {{ row.business_date }}</text
                    ><text class="plain">{{ row.note }}</text
                    ><text v-if="row.reversed" class="small muted">{{ t('Reversed') }}</text></view
                ></view
            ><view v-if="r.missingRates > 0 && r.version !== 'partner'" class="panel"
                ><text class="heading">{{ t('Rates pending') }} ({{ r.unvalued.total }})</text
                ><view v-for="row in r.unvalued.items" :key="row.id" class="detail"
                    ><text>{{ row.fee_amount }} {{ row.asset_code }}</text
                    ><text class="small muted">{{ row.id }}</text></view
                ></view
            ><ReportPagination
                :page="r.journal.page"
                :has-more="more"
                @change="(page) => go('/promotion/stock?page=' + page, true)"
            /><text class="muted small">{{
                t(
                    r.version === 'partner'
                        ? 'Includes all descendants, including partner branches, but excludes your own external deposits and withdrawals. Teams overlap; do not add reports together. Historical totals use current team relationships.'
                        : 'Includes this account and all descendants. Historical totals use current team relationships.',
                )
            }}</text></view
        ></PageShell
    >
</template>
<style scoped>
.stock {
    display: flex;
    flex-direction: column;
    gap: 18px;
    font-size: 14px;
    line-height: 22px;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.hero {
    padding: 22px;
    border-radius: 18px;
    background: #173f33;
    color: #f5ead0;
}
.total {
    display: block;
    font-size: 30px;
    line-height: 40px;
    font-weight: 600;
    margin-top: 10px;
    overflow-wrap: anywhere;
}
.panel {
    padding: 18px;
    border: 1px solid #e1e7dd;
    border-radius: 16px;
    background: white;
}
.panel > .small {
    display: block;
    margin-bottom: 8px;
}
.heading {
    display: block;
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 14px;
}
.row {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px 16px;
    padding: 10px 0;
    border-bottom: 1px solid #edf0e9;
    font-size: 13px;
}
.row > text:last-child {
    font-weight: 500;
    overflow-wrap: anywhere;
}
.row:last-child {
    border: 0;
}
.warning {
    padding: 14px;
    background: #fffbeb;
    border: 1px solid #fde68a;
    border-radius: 12px;
    color: #854d0e;
    font-size: 13px;
    line-height: 22px;
}
.expand {
    background: none;
    padding: 12px 0;
    margin: 0;
    font-size: 13px;
    line-height: 22px;
    text-align: left;
}
.detail {
    display: flex;
    flex-direction: column;
    gap: 5px;
    padding: 14px 0;
    border-top: 1px solid #edf0e9;
    font-size: 13px;
    line-height: 21px;
    overflow-wrap: anywhere;
}
.strong {
    font-weight: 600;
}
.trend {
    margin-top: 18px;
}
.plain {
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}
</style>
