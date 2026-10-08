<script setup lang="ts">
import { t } from '../lib/i18n';
import { back } from '../lib/navigation';
import UiIcon from './UiIcon.vue';
defineProps<{ full?: boolean; compact?: boolean }>();
</script>
<template>
    <view class="page-skeleton" :class="{ 'skeleton-full': full, 'skeleton-compact': compact }" aria-busy="true" :aria-label="t('Loading…')">
        <view v-if="full" class="skeleton-header">
            <button class="skeleton-back" :aria-label="t('Back')" @click="back()"><UiIcon name="arrow-left" :size="24" /></button>
            <text class="skeleton-status" role="status">{{ t('Loading…') }}</text>
        </view>
        <view class="skeleton-content" aria-hidden="true">
            <view v-if="!compact" class="skeleton-block skeleton-title" />
            <view v-if="!compact" class="skeleton-panel">
                <view class="skeleton-block skeleton-short" />
                <view class="skeleton-block skeleton-value" />
                <view class="skeleton-block skeleton-line" />
            </view>
            <view class="skeleton-list">
                <view v-for="row in 3" :key="row" class="skeleton-row">
                    <view class="skeleton-block skeleton-icon" />
                    <view class="skeleton-lines"><view class="skeleton-block skeleton-line" /><view class="skeleton-block skeleton-short" /></view>
                </view>
            </view>
            <view v-if="!compact" class="skeleton-block skeleton-action" />
        </view>
        <text v-if="!full" class="skeleton-status" role="status">{{ t('Loading…') }}</text>
    </view>
</template>
<style scoped>
.page-skeleton { width: 100%; box-sizing: border-box; color: #68736e; }
.skeleton-full { min-height: 100vh; background: #f7f6f0; padding-top: 24px; padding-top: env(safe-area-inset-top, 24px); }
.skeleton-header { display: flex; align-items: center; height: 64px; padding: 0 16px; max-width: 750px; margin: 0 auto; }
.skeleton-back { display: flex; align-items: center; justify-content: center; width: 44px; height: 44px; margin: 0 12px 0 0; padding: 0; background: transparent; border: 0; }
.skeleton-back::after { border: 0; }
.skeleton-content { width: 100%; max-width: 750px; margin: 0 auto; box-sizing: border-box; }
.skeleton-full .skeleton-content { padding: 16px 20px 32px; }
.skeleton-block { background: #e0e7e2; border-radius: 8px; animation: skeleton-pulse 1.6s ease-in-out infinite; }
.skeleton-title { width: 42%; height: 24px; margin-bottom: 24px; }
.skeleton-panel { padding: 24px; background: #fff; border: 1px solid #e2e7e4; border-radius: 16px; margin-bottom: 20px; }
.skeleton-short { width: 38%; height: 12px; }
.skeleton-value { width: 62%; height: 32px; margin: 20px 0; }
.skeleton-line { width: 80%; height: 16px; }
.skeleton-list { padding: 0 16px; border: 1px solid #e2e7e4; border-radius: 16px; background: #fff; }
.skeleton-row { display: flex; align-items: center; gap: 16px; padding: 20px 0; }
.skeleton-row + .skeleton-row { border-top: 1px solid #eef1ee; }
.skeleton-icon { width: 40px; height: 40px; flex-shrink: 0; border-radius: 12px; }
.skeleton-lines { flex: 1; min-width: 0; }
.skeleton-lines .skeleton-short { margin-top: 12px; }
.skeleton-action { height: 48px; margin-top: 24px; }
.skeleton-status { display: block; font-size: 14px; padding: 12px 0; }
.skeleton-compact .skeleton-row { padding: 16px 0; }
@keyframes skeleton-pulse { 0%, 100% { opacity: 1; } 50% { opacity: .5; } }
@media (prefers-reduced-motion: reduce) { .skeleton-block { animation: none; } }
</style>
