<script setup lang="ts">
import { t } from '../lib/i18n';
import PageSkeleton from './PageSkeleton.vue';
import UiIcon from './UiIcon.vue';
const actions = [
    { label: 'Top up', icon: 'arrow-down' },
    { label: 'Withdraw', icon: 'arrow-up' },
    { label: 'Exchange', icon: 'arrow-left-right' },
    { label: 'Transfer', icon: 'arrow-left-right' },
];
</script>
<template>
    <view class="asset-skeleton" aria-busy="true" :aria-label="t('Loading…')">
        <view class="balance-placeholder">
            <text class="balance-label">{{ t('Estimated total assets') }}</text>
            <view class="balance-value"><text>—</text><text class="currency">USDT</text></view>
        </view>
        <view class="actions">
            <button v-for="action in actions" :key="action.label" disabled class="action">
                <view class="icon"><UiIcon :name="action.icon" :size="22" /></view>
                <text>{{ t(action.label) }}</text>
            </button>
        </view>
        <PageSkeleton compact />
    </view>
</template>
<style scoped>
.balance-placeholder {
    padding: 4px 8px;
    text-align: center;
    margin-bottom: 24px;
}
.balance-label {
    display: block;
    line-height: 44px;
    font-size: 12px;
    color: #68736e;
}
.balance-value {
    display: flex;
    justify-content: center;
    align-items: baseline;
    gap: 8px;
    font-size: 30px;
    line-height: 36px;
    font-weight: 600;
}
.currency {
    font-size: 16px;
    font-weight: 400;
}
.actions {
    display: flex;
    justify-content: space-evenly;
    gap: 8px;
    margin-bottom: 24px;
}
.action {
    display: flex;
    min-width: 0;
    flex: 1;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    padding: 0;
    background: transparent;
    font-size: 12px;
    line-height: 1.5;
    color: #68736e;
}
.icon {
    display: flex;
    justify-content: center;
    align-items: center;
    width: 48px;
    height: 48px;
    background: #e0e7e2;
    border-radius: 50%;
}
</style>
