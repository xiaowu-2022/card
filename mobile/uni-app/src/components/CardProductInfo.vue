<script setup lang="ts">
import { t } from '../lib/i18n';
defineProps<{ monthlyFeeText?: string | null; notes?: string | null }>();
</script>
<template>
    <view v-if="monthlyFeeText || notes" class="product-info">
        <view v-if="monthlyFeeText" class="info-block">
            <text class="info-label muted">{{ t('Monthly fee') }}</text>
            <text class="info-text">{{ monthlyFeeText }}</text>
        </view>
        <view v-if="notes" class="info-block">
            <text class="info-label muted">{{ t('Card notes') }}</text>
            <text class="info-text">{{ notes }}</text>
        </view>
    </view>
</template>
<style scoped>
.info-block {
    display: flex;
    flex-wrap: wrap;
    justify-content: space-between;
    gap: 8px;
    padding: 12px 0;
    border-bottom: 1px solid #e2e7e4;
    font-size: 14px;
    line-height: 22px;
}
.info-label {
    flex-shrink: 0;
}
.info-text {
    min-width: 0;
    max-width: 100%;
    font-weight: 600;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}
</style>
