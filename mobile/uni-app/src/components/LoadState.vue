<script setup lang="ts">
import PageSkeleton from './PageSkeleton.vue';
import { t } from '../lib/i18n';
defineProps<{ loading: boolean; failed: boolean; hasContent?: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <view v-if="failed && !hasContent" class="empty"
        ><text class="body error">{{ t('Unable to load. Please try again.') }}</text
        ><button class="secondary" @click="$emit('retry')">{{ t('Retry') }}</button></view
    >
    <PageSkeleton v-else-if="loading && !hasContent" />
    <template v-else><view v-if="hasContent && (loading || failed)" class="refresh-status" role="status"><text>{{ t(loading ? 'Updating…' : 'Update failed. Please retry.') }}</text><button v-if="failed && !loading" class="secondary" @click="$emit('retry')">{{ t('Retry') }}</button></view><slot /></template>
</template>

<style scoped>
.refresh-status { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:8px 0; font-size:13px; color:#68736e; }
.refresh-status button { padding:4px 12px; min-height:36px; font-size:13px; }
</style>
