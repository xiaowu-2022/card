<script setup lang="ts">
import { appUpdate, checkAppUpdate, downloadAppUpdate } from '../lib/app-update';
import { t } from '../lib/i18n';
</script>
<template>
    <view v-if="appUpdate.status !== 'ready'" class="app-update-gate" @touchmove.stop.prevent>
        <view class="update-dialog">
            <text class="update-title">{{ t('App update') }}</text>
            <template v-if="appUpdate.status === 'required'">
                <text>{{ t('Install the latest version to continue using the app.') }}</text>
                <text class="update-version">{{ appUpdate.version }}</text>
                <button class="primary" @click="downloadAppUpdate">{{ t('Download update') }}</button>
            </template>
            <text v-else-if="appUpdate.status === 'checking'">{{ t('Checking app version…') }}</text>
            <template v-else>
                <text>{{ t('Unable to check the app version. Check your connection and retry.') }}</text>
                <button class="primary" @click="checkAppUpdate(true)">{{ t('Retry') }}</button>
            </template>
        </view>
    </view>
</template>
<style scoped>
.app-update-gate { position: fixed; inset: 0; z-index: 2147483647; background: #f7f6f0; display: flex; align-items: center; justify-content: center; padding: 28px; }
.update-dialog { width: 100%; max-width: 400px; display: flex; flex-direction: column; gap: 24px; text-align: center; line-height: 1.7; }
.update-title { font-size: 22px; font-weight: 600; }
.update-version { color: #7a867f; }
</style>
