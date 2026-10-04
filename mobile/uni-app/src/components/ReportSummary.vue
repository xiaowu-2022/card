<script setup lang="ts">
import { ref } from 'vue';
import { t } from '../lib/i18n';
import { reportMoney, fullMoney, incomeLabels, type IncomeTotals } from '../lib/promotion-report';
defineProps<{ totals: IncomeTotals; title: string }>();
const expanded = ref<Record<string, boolean>>({});
</script>
<template>
    <view class="report-income"
        ><view class="eyebrow"
            ><text>{{ title }}</text
            ><text>USDT</text></view
        ><view class="total" @click="expanded.total = !expanded.total"
            ><text>{{ reportMoney(totals.total).replace(' USDT', '') }}</text
            ><text v-if="expanded.total" class="exact">{{ fullMoney(totals.total) }}</text></view
        ><view class="split"
            ><view
                v-for="(label, kind) in incomeLabels"
                :key="kind"
                @click="expanded[kind] = !expanded[kind]"
                ><text class="muted">{{ t(label) }}</text
                ><text class="amount">{{
                    reportMoney((totals[kind as keyof IncomeTotals] ?? '0')).replace(' USDT', '')
                }}</text
                ><text v-if="expanded[kind]" class="exact">{{
                    fullMoney((totals[kind as keyof IncomeTotals] ?? '0'))
                }}</text></view
            ></view
        ></view
    >
</template>
<style scoped>
.report-income {
    padding: 20px;
    border: 1px solid #d9e4dc;
    border-radius: 18px;
    background: linear-gradient(125deg, #eef6ed, #faf6e8);
    color: #254235;
}
.eyebrow {
    display: flex;
    justify-content: space-between;
    gap: 8px;
    font-size: 13px;
    color: #657767;
}
.total {
    font-size: 32px;
    line-height: 40px;
    font-weight: 600;
    margin: 8px 0 18px;
    font-variant-numeric: tabular-nums;
    overflow-wrap: anywhere;
}
.exact {
    display: block;
    font-size: 12px;
    font-weight: 400;
    line-height: 20px;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
.split {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 12px;
    padding-top: 16px;
    border-top: 1px solid #2542351a;
}
.split .muted {
    display: block;
    font-size: 12px;
    line-height: 18px;
}
.amount {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-top: 4px;
    overflow-wrap: anywhere;
}
</style>
