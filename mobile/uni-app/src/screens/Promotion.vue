<script setup lang="ts">
import PageShell from '../components/PageShell.vue';
import PaidPromotionSummary from '../components/PaidPromotionSummary.vue';
import UiIcon from '../components/UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
import { promotionTableAmount } from '../lib/promotion';
import type { PaidPromotionData } from '../lib/promotion-types';
defineProps<{
    page: { promotion: { paid: PaidPromotionData; myCommission: string; supported: boolean } };
}>();
const links = [
    { href: '/promotion/daily', label: 'Daily data', icon: 'sliders-horizontal' },
    { href: '/promotion/direct', label: 'Team members', icon: 'user-plus' },
    { href: '/promotion/commissions', label: 'Commission details', icon: 'receipt-text' },
];
</script>
<template>
    <PageShell :title="t('Invitation data')" back="/promotion" active="account" white
        ><view class="promotion"
            ><text v-if="!page.promotion.supported">{{
                t('Promotion currently supports USDT accounts only.')
            }}</text
            ><view class="commission"
                ><text class="label">{{ t('My total commission') }}</text
                ><text class="amount"
                    >{{ promotionTableAmount(page.promotion.myCommission) }}
                    <text class="asset">USDT</text></text
                ><text class="note">{{
                    t('Commission is automatically credited to your USDT balance.')
                }}</text></view
            ><view class="destinations"
                ><button v-for="link in links" :key="link.href" @click="go(link.href)">
                    <UiIcon :name="link.icon" :size="22" /><text>{{ t(link.label) }}</text>
                </button></view
            ><PaidPromotionSummary :paid="page.promotion.paid" /></view
    ></PageShell>
</template>
<style scoped>
.promotion {
    display: flex;
    flex-direction: column;
    gap: 20px;
    font-size: 14px;
}
.commission {
    background: #193b31;
    color: white;
    border: 1px solid #c7ac6b;
    border-radius: 18px;
    padding: 20px;
}
.label {
    display: block;
    font-size: 14px;
    line-height: 22px;
    color: #e2ecd8;
}
.amount {
    display: block;
    margin-top: 8px;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
    overflow-wrap: anywhere;
    color: #fff0bb;
    font-variant-numeric: tabular-nums;
}
.asset {
    font-size: 14px;
    font-weight: 400;
}
.note {
    display: block;
    margin-top: 16px;
    font-size: 12px;
    line-height: 20px;
    color: #d0e3d8;
}
.destinations {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
}
.destinations button {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
    min-height: 88px;
    background: #f3f7ef;
    border-radius: 14px;
    padding: 16px 8px;
    margin: 0;
    font-size: 13px;
    line-height: 20px;
    overflow-wrap: anywhere;
}
</style>
