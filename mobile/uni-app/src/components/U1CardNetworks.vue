<script setup lang="ts">
import { staticAsset } from '../lib/origin';
</script>
<template>
    <view class="u1-networks" aria-label="Mastercard and Visa">
        <view class="network-logos">
            <image :src="staticAsset('images/cards/mastercard.svg')" mode="aspectFit" class="mastercard" />
            <text class="separator">*</text>
            <image :src="staticAsset('images/cards/visa.svg')" mode="aspectFit" class="visa" />
        </view>
        <text class="network-label">master * visa</text>
    </view>
</template>
<style scoped>
.u1-networks { width: 116px; flex-shrink: 0; align-self: flex-end; display: flex; flex-direction: column; align-items: center; gap: 3px; }
.network-logos { width: 100%; display: flex; align-items: center; justify-content: center; gap: 7px; }
.mastercard { width: 46px; height: 34px; }
.visa { width: 46px; height: 24px; }
.separator { font-size: 12px; color: #e8d5ad; }
.network-label { color: #fff; font-size: 10px; line-height: 12px; letter-spacing: .5px; white-space: nowrap; }
</style>
