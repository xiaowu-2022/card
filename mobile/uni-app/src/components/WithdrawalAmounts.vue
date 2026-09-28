<script setup lang="ts">
import { t } from '../lib/i18n';
import { exactAmount } from '../generated/exact-amount';
defineProps<{ fee: string; receive: string | null }>();
</script>
<template>
    <view class="amounts"
        ><view
            ><text class="muted">{{ t('Withdrawal fee') }}</text
            ><text>{{ exactAmount(fee) }} USDT</text></view
        ><view
            ><text class="muted">{{ t('Amount to receive') }}</text
            ><text>{{ receive === null ? '—' : exactAmount(receive) }} USDT</text></view
        ></view
    >
</template>
<style scoped>
.amounts {
    font-size: 14px;
    line-height: 22px;
}
.amounts > view {
    display: flex;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 8px;
}
.amounts > view + view {
    margin-top: 12px;
}
.amounts > view:last-child > text:last-child {
    font-weight: 600;
}
</style>
