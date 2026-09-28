<script setup lang="ts">
import UiIcon from './UiIcon.vue';
import { t } from '../lib/i18n';
withDefaults(defineProps<{ brand: string; tone?: string }>(), { tone: 'green' });
</script>
<template>
    <view class="marketing-card" :class="'marketing-card-' + tone" aria-hidden="true"
        ><UiIcon name="aperture" class="marketing-card-symbol" /><text
            class="marketing-card-brand"
            >{{ brand }}</text
        ><view class="marketing-card-orbit" /><view class="marketing-card-bottom"
            ><UiIcon name="credit-card" /><text>{{ t('Mastercard U Card') }}</text></view
        ></view
    >
</template>
