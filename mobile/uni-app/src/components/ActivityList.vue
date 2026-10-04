<script setup lang="ts">
import { t, dateTime } from '../lib/i18n';
import { exactAmount } from '../generated/exact-amount';
import UiIcon from './UiIcon.vue';
import Modal from './Modal.vue';
import { ref, watch } from 'vue';
import { useSensitiveScreen } from '../lib/sensitive';
import type { FundsMovement } from '../lib/assets';
type Activity = FundsMovement & { asset: string };
const selected = ref<Activity | null>(null);
useSensitiveScreen(() => {
    selected.value = null;
});
const props = defineProps<{
    items: Activity[];
    hidden?: boolean;
}>();
watch(
    () => props.items,
    () => {
        selected.value = null;
    },
);
function signed(value: string) {
    const amount = exactAmount(value);
    return value.startsWith('-') || amount === '0' ? amount : '+' + amount;
}
</script>
<template>
    <view v-if="!items.length" class="activity-empty">{{ t('No activity yet') }}</view
    ><button
        v-for="row in items"
        :key="row.id"
        class="activity-row"
        :aria-label="t('Funds movement details') + ': ' + t(row.kind)"
        @click="selected = row"
    >
        <view class="activity-icon" :class="{ debit: row.amount.startsWith('-') }"
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
        ><UiIcon name="chevron-right" :size="16" />
    </button>
    <Modal :open="!!selected" :title="t('Funds movement details')" @close="selected = null">
        <view v-if="selected" class="movement-details">
            <view
                ><text class="muted">{{ t('Transaction type') }}</text
                ><text>{{ t(selected.kind) }}</text></view
            >
            <view v-if="selected.details">
                <text class="muted">{{ t('Purpose') }}</text>
                <text>{{ t(selected.details.reason) }}</text>
            </view>
            <template v-if="selected.details?.counterparty">
                <view>
                    <text class="muted">{{ t(selected.details.counterparty.role === 'recipient' ? 'Recipient account ID' : 'Sender account ID') }}</text>
                    <text selectable>{{ selected.details.counterparty.accountId ?? t('Unavailable') }}</text>
                </view>
                <view>
                    <text class="muted">{{ t(selected.details.counterparty.role === 'recipient' ? 'Recipient current email' : 'Sender current email') }}</text>
                    <text selectable>{{ selected.details.counterparty.email ?? t('Unavailable') }}</text>
                </view>
            </template>
            <view v-if="selected.details?.reference">
                <text class="muted">{{ t('Business reference') }}</text>
                <text selectable>{{ selected.details.reference }}</text>
            </view>
            <view
                ><text class="muted">{{ t('Direction') }}</text
                ><text>{{
                    t(selected.amount.startsWith('-') ? 'Wallet debit' : 'Wallet credit')
                }}</text></view
            >
            <view
                ><text class="muted">{{ t('Quantity') }}</text
                ><text>{{ hidden ? '••••••' : signed(selected.amount) }}</text></view
            >
            <view
                ><text class="muted">{{ t('Currency') }}</text
                ><text>{{ selected.asset }}</text></view
            >
            <view
                ><text class="muted">{{ t('Time') }}</text
                ><text>{{ dateTime(selected.time) }}</text></view
            >
            <view
                ><text class="muted">{{ t('Funds reference') }}</text
                ><text selectable>{{ selected.id }}</text></view
            >
        </view>
    </Modal>
</template>
<style scoped>
.activity-empty {
    padding: 28px 0;
    text-align: center;
    font-size: 14px;
    color: #68736e;
}
.movement-details > view {
    display: flex;
    justify-content: space-between;
    gap: 20px;
    padding: 14px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
}
.movement-details > view > text:last-child {
    text-align: right;
    flex: 1;
    min-width: 0;
    overflow-wrap: anywhere;
}
.activity-row {
    width: 100%;
    text-align: left;
    background: transparent;
    border-radius: 0;
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
