<script setup lang="ts">
import { t } from '../lib/i18n';
defineProps<{ monthlyFeeText?: string | null; notes?: string | null }>();
</script>
<template>
    <view v-if="monthlyFeeText || notes" class="product-info">
        <view v-if="monthlyFeeText" class="info-block">
            <text class="info-label">{{ t('Monthly fee') }}</text>
            <text class="info-text">{{ monthlyFeeText }}</text>
        </view>
        <view v-if="notes" class="info-block">
            <text class="info-label">{{ t('Card notes') }}</text>
            <text class="info-text">{{ notes }}</text>
        </view>
    </view>
</template>
<style scoped>
.product-info {
    margin: 16px 0;
    padding: 16px;
    border: 1px solid #e5dece;
    border-radius: 12px;
    background: #f9f6ed;
}
.info-block + .info-block {
    margin-top: 14px;
}
.info-label {
    display: block;
    color: #766b56;
    font-size: 13px;
    margin-bottom: 6px;
}
.info-text {
    display: block;
    color: #25241f;
    font-size: 14px;
    line-height: 1.7;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    word-break: break-word;
}
</style>
