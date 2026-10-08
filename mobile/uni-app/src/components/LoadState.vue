<script setup lang="ts">
import PageSkeleton from './PageSkeleton.vue';
import { t } from '../lib/i18n';
defineProps<{ loading: boolean; failed: boolean }>();
defineEmits<{ retry: [] }>();
</script>
<template>
    <view v-if="failed" class="empty"
        ><text class="body error">{{ t('Unable to load. Please try again.') }}</text
        ><button class="secondary" @click="$emit('retry')">{{ t('Retry') }}</button></view
    >
    <PageSkeleton v-else-if="loading" />
    <slot v-else />
</template>
