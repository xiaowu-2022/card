<script setup lang="ts">
defineProps<{ asset: string }>();
const colors: Record<string, string> = {
    USDT: '#059669',
    USDC: '#2563eb',
    ETH: '#6366f1',
    BTC: '#f97316',
};
const symbols: Record<string, string> = { USDT: '₮', USDC: '$', ETH: 'Ξ', BTC: '₿' };
</script>
<template>
    <view class="asset-symbol" :style="{ background: colors[asset] }">{{
        symbols[asset] ?? asset
    }}</view>
</template>
<style scoped>
.asset-symbol {
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
    width: 36px;
    height: 36px;
    border-radius: 50%;
    font-size: 18px;
    font-weight: 600;
    color: white;
}
</style>
