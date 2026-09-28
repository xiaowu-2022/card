<script setup lang="ts">
import { t } from '../lib/i18n';
</script>
<template>
    <view class="wealth-notice"
        ><text>{{
            t(
                'Interest is automatically paid to your balance every month. Paid interest is recovered on early withdrawal.',
            )
        }}</text
        ><text>{{
            t(
                'Early withdrawal cancels the whole deposit. You receive principal minus interest already paid; including those payments, you recover your principal.',
            )
        }}</text></view
    >
</template>
<style scoped>
.wealth-notice {
    display: flex;
    flex-direction: column;
    gap: 8px;
    padding: 16px;
    background: #f0f3f1;
    border-radius: 12px;
    font-size: 14px;
    line-height: 24px;
    margin-bottom: 20px;
}
</style>
