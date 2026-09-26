<script setup lang="ts">
import { t } from '../lib/i18n';
defineProps<{ loading: boolean; failed: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
  <view v-if="failed" class="empty"><text class="body error">{{ t('Unable to complete this request. Check your information and current status.') }}</text><button class="secondary" @click="$emit('retry')">{{ t('Retry') }}</button></view>
  <view v-else-if="loading" class="empty" aria-busy="true">…</view>
  <slot v-else />
</template>
