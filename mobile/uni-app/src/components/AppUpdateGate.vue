<script setup lang="ts">
import { appUpdate, dismissAppUpdate, downloadAppUpdate } from '../lib/app-update';
import { t } from '../lib/i18n';
</script>
<template>
    <view v-if="appUpdate.status === 'available'" class="app-update-notice">
        <text class="update-title">{{ t('A new app version is available.') }} {{ appUpdate.version }}</text>
        <view class="update-actions">
            <button class="secondary" @click="dismissAppUpdate">{{ t('Not now') }}</button>
            <button class="primary" @click="downloadAppUpdate">{{ t('Download update') }}</button>
        </view>
    </view>
</template>
<style scoped>
.app-update-notice { position: fixed; top: calc(12px + env(safe-area-inset-top)); left: 16px; right: 16px; z-index: 1100; background: #f7f6f0; border: 1px solid #dde6e0; border-radius: 12px; padding: 16px; box-shadow: 0 4px 16px #0002; }
.update-title { display: block; font-size: 16px; }
.update-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 12px; }
.update-actions button { margin: 0; font-size: 14px; }
</style>
