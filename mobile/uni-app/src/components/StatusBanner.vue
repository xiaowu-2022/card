<script setup lang="ts">
import UiIcon from './UiIcon.vue';
import { go } from '../lib/navigation';
withDefaults(
    defineProps<{
        title: string;
        description: string;
        tone?: string;
        action?: { label: string; href: string };
    }>(),
    { tone: 'neutral' },
);
</script>
<template>
    <view class="status-banner" :class="tone"
        ><UiIcon
            :name="
                (
                    {
                        success: 'circle-check',
                        warning: 'triangle-alert',
                        pending: 'clock3',
                    } as Record<string, string>
                )[tone] ?? 'info'
            "
            :size="20"
        /><view class="status-content"
            ><text class="status-title">{{ title }}</text
            ><text class="status-description">{{ description }}</text
            ><button v-if="action" class="primary" @click="go(action.href)">
                {{ action.label }}
            </button></view
        ></view
    >
</template>
<style scoped>
.status-banner {
    display: flex;
    align-items: flex-start;
    gap: 12px;
    padding: 20px;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    background: white;
    margin-bottom: 24px;
}
.status-content {
    flex: 1;
    min-width: 0;
}
.status-title {
    display: block;
    font-size: 16px;
    font-weight: 600;
    line-height: 24px;
}
.status-description {
    display: block;
    overflow-wrap: anywhere;
    font-size: 14px;
    line-height: 24px;
    opacity: 0.8;
    margin-top: 4px;
}
.status-content .primary {
    margin-top: 16px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 48px;
    max-width: 100%;
    border-radius: 999px;
    background: #171915;
    color: #fff;
    font-size: 16px;
    font-weight: 400;
    padding: 10px 24px;
}
.success {
    background: #ecfdf5;
    border-color: #a7f3d0;
    color: #022c22;
}
.warning {
    background: #fffbeb;
    border-color: #fde68a;
    color: #451a03;
}
.pending {
    background: #f0f9ff;
    border-color: #bae6fd;
    color: #082f49;
}
</style>
