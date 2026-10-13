<script setup lang="ts">
import { t } from '../lib/i18n';
defineProps<{ loading: boolean; failed: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <!-- #ifdef H5 -->
    <view v-if="loading || failed" class="refresh-status" role="status"
        ><text>{{ t(loading ? 'Updating…' : 'Update failed. Please retry.') }}</text
        ><button v-if="failed && !loading" class="secondary" @click="$emit('retry')">
            {{ t('Retry') }}
        </button></view
    >
    <!-- #endif -->
</template>
<style scoped>
.refresh-status {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    padding: 8px 0;
    font-size: 13px;
    color: #68736e;
}
.refresh-status button {
    padding: 4px 12px;
    min-height: 36px;
    font-size: 13px;
}
</style>
