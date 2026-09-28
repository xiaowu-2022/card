<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import UiIcon from '../components/UiIcon.vue';
import { t, dateTime } from '../lib/i18n';
import { go } from '../lib/navigation';
import { exactAmount } from '../generated/exact-amount';
defineProps<{
    page: {
        assets: { asset: string; principal: string; net: string }[];
        principalEstimate: string | null;
        nextInterest: {
            dueAt: string;
            overdue: boolean;
            amounts: { asset: string; amount: string }[];
        } | null;
    };
}>();
const colors = ['#169b75', '#2876de', '#7561da', '#f58a20'];
const actions = [
    { view: 'details', label: 'Wealth details action', icon: 'list' },
    { view: 'deposit', label: 'Wealth deposit action', icon: 'arrow-down-to-line' },
    { view: 'withdraw', label: 'Wealth withdraw action', icon: 'arrow-up-from-line' },
];
</script>
<template>
    <PageShell :title="t('Wealth management')" back="/dashboard" active="assets"
        ><view class="wealth-overview"
            ><view class="wealth-summary"
                ><text class="muted">{{ t('Deposited amount') }}</text
                ><text class="principal-estimate">{{
                    page.principalEstimate === null
                        ? t('Valuation unavailable')
                        : '≈ ' + exactAmount(page.principalEstimate) + ' USDT'
                }}</text
                ><view class="next-interest"
                    ><text class="small muted">{{ t('Next expected interest payment') }}</text
                    ><template v-if="page.nextInterest"
                        ><text class="due-time">{{ dateTime(page.nextInterest.dueAt) }}</text
                        ><text
                            v-for="amount in page.nextInterest.amounts"
                            :key="amount.asset"
                            class="next-amount"
                            >{{ exactAmount(amount.amount) }} {{ amount.asset }}</text
                        ><text v-if="page.nextInterest.overdue" class="small muted">{{
                            t('Interest settlement pending')
                        }}</text></template
                    ><text v-else class="no-interest">{{
                        t('No upcoming interest payment')
                    }}</text></view
                ></view
            ><text class="section-title">{{ t('All wealth wallets') }}</text
            ><view class="wealth-assets"
                ><view v-for="(asset, index) in page.assets" :key="asset.asset" class="wealth-asset"
                    ><view class="asset-heading"
                        ><view class="dot" :style="{ background: colors[index] }" /><text>{{
                            asset.asset
                        }}</text></view
                    ><view class="asset-metric"
                        ><text class="small muted">{{ t('Wealth principal') }}</text
                        ><text class="metric-principal">{{
                            exactAmount(asset.principal)
                        }}</text></view
                    ><view class="asset-metric"
                        ><text class="small muted">{{ t('Cumulative net earnings') }}</text
                        ><text class="metric-net">{{ exactAmount(asset.net) }}</text></view
                    ><view class="asset-actions"
                        ><button
                            v-for="action in actions"
                            :key="action.view"
                            class="asset-action"
                            @click="go('/wealth/assets/' + asset.asset + '?view=' + action.view)"
                        >
                            <UiIcon :name="action.icon" :size="16" /><text>{{
                                t(action.label)
                            }}</text>
                        </button></view
                    ></view
                ></view
            ><text class="wealth-note">{{
                t(
                    'Interest is automatically paid to your balance every month. Paid interest is recovered on early withdrawal.',
                )
            }}</text></view
        ></PageShell
    >
</template>
<style scoped>
.wealth-summary {
    padding: 20px;
    border-radius: 24px;
    background: linear-gradient(135deg, #d1fae5, #f7fee7);
    color: #0f172a;
}
.principal-estimate {
    display: block;
    margin-top: 8px;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    letter-spacing: -0.025em;
    overflow-wrap: anywhere;
}
.next-interest {
    display: flex;
    flex-direction: column;
    gap: 8px;
    margin-top: 16px;
    padding-top: 16px;
    border-top: 1px solid #064e3b1a;
}
.small {
    font-size: 12px;
    line-height: 20px;
}
.due-time,
.no-interest {
    font-size: 14px;
}
.due-time {
    font-weight: 500;
}
.next-amount {
    font-size: 18px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.section-title {
    display: block;
    margin: 20px 0 12px;
    font-weight: 600;
}
.wealth-assets {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
}
.wealth-asset {
    padding: 16px;
    background: white;
    border-radius: 16px;
    min-width: 0;
}
.asset-heading {
    display: flex;
    align-items: center;
    gap: 8px;
    font-weight: 600;
}
.dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
}
.asset-metric {
    margin-top: 12px;
}
.asset-metric:first-of-type {
    margin-top: 16px;
}
.metric-principal {
    display: block;
    margin-top: 4px;
    font-size: 18px;
    font-weight: 600;
    overflow-wrap: anywhere;
}
.metric-net {
    display: block;
    margin-top: 4px;
    font-size: 14px;
    font-weight: 500;
    overflow-wrap: anywhere;
}
.asset-actions {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 4px;
    margin-top: 16px;
    padding-top: 8px;
    border-top: 1px solid #e2e7e4;
}
.asset-action {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 6px;
    min-width: 0;
    min-height: 56px;
    padding: 8px 2px;
    background: none;
    border-radius: 8px;
    font-size: 12px;
    line-height: 1.4;
    overflow-wrap: anywhere;
}
.wealth-note {
    display: block;
    padding: 20px 4px 0;
    font-size: 12px;
    line-height: 20px;
    color: #68736e;
}
</style>
