<script setup lang="ts">
import { t } from '../lib/i18n';
defineProps<{ page: number; hasMore: boolean }>();
defineEmits<{ change: [page: number] }>();
</script>
<template>
    <view v-if="page > 1 || hasMore" class="report-pagination"
        ><button class="secondary" :disabled="page <= 1" @click="$emit('change', page - 1)">
            {{ t('Previous') }}</button
        ><text>{{ page }}</text
        ><button class="secondary" :disabled="!hasMore" @click="$emit('change', page + 1)">
            {{ t('Next') }}
        </button></view
    >
</template>
<style scoped>
.report-pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 12px;
    font-size: 14px;
    margin-top: 16px;
}
.report-pagination button {
    font-size: 14px;
    margin: 0;
    line-height: 24px;
    padding: 8px 16px;
    min-height: 44px;
}
</style>
