<script setup lang="ts">
import { t, dateTime } from '../lib/i18n';
import { exactAmount } from '../generated/exact-amount';
import UiIcon from './UiIcon.vue';
defineProps<{
    items: { id: string; asset: string; kind: string; amount: string; time: string }[];
    hidden?: boolean;
}>();
function signed(value: string) {
    const amount = exactAmount(value);
    return value.startsWith('-') || amount === '0' ? amount : '+' + amount;
}
</script>
<template>
    <view v-if="!items.length" class="activity-empty">{{ t('No activity yet') }}</view
    ><view v-for="row in items" :key="row.id" class="activity-row"
        ><view class="activity-icon" :class="{ debit: row.amount.startsWith('-') }"
            ><UiIcon
                :name="row.amount.startsWith('-') ? 'arrow-up-right' : 'arrow-down-left'"
                :size="16" /></view
        ><view class="activity-body"
            ><view class="activity-heading"
                ><text class="activity-kind">{{ t(row.kind) }}</text
                ><text class="activity-amount" :class="{ debit: row.amount.startsWith('-') }"
                    >{{ hidden ? '••••••' : signed(row.amount) }}
                    <text class="activity-asset">{{ row.asset }}</text></text
                ></view
            ><view class="activity-meta"
                ><text>{{ dateTime(row.time) }}</text
                ><text>{{
                    t(row.amount.startsWith('-') ? 'Wallet debit' : 'Wallet credit')
                }}</text></view
            ></view
        ></view
    >
</template>
<style scoped>
.activity-empty {
    padding: 28px 0;
    text-align: center;
    font-size: 14px;
    color: #68736e;
}
.activity-row {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 16px 0;
    font-size: 14px;
    border-bottom: 1px solid #0000000d;
}
.activity-row:last-child {
    border: 0;
}
.activity-icon {
    display: flex;
    align-items: center;
    justify-content: center;
    width: 32px;
    height: 32px;
    flex-shrink: 0;
    border-radius: 50%;
    background: #ecfdf5;
}
.activity-icon.debit {
    background: #f0f3f1;
}
.activity-body {
    flex: 1;
    min-width: 0;
}
.activity-heading {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    column-gap: 12px;
    row-gap: 4px;
}
.activity-kind {
    font-weight: 500;
}
.activity-amount {
    min-width: 0;
    overflow-wrap: anywhere;
    font-weight: 600;
    font-variant-numeric: tabular-nums;
    color: #047857;
}
.activity-amount.debit {
    color: #171c19;
}
.activity-asset {
    font-size: 12px;
    font-weight: 400;
    white-space: nowrap;
}
.activity-meta {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 4px 12px;
    margin-top: 4px;
    font-size: 12px;
    color: #68736e;
}
</style>
