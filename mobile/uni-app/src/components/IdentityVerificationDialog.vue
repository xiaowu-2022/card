<script setup lang="ts">
import UiIcon from './UiIcon.vue';
import { t } from '../lib/i18n';
import { go } from '../lib/navigation';
defineProps<{ open: boolean; pending?: boolean }>();
const emit = defineEmits<{ close: [] }>();
</script>
<template>
    <view v-if="open" class="identity-overlay" @click.self="emit('close')"
        ><view
            class="identity-dialog"
            role="dialog"
            aria-modal="true"
            :aria-label="t(pending ? 'Under review' : 'Complete identity verification')"
            ><button class="close" :aria-label="t('Close')" @click="emit('close')">
                <UiIcon name="x" :size="16" /></button
            ><view class="symbol"><UiIcon name="shield-alert" color="#92400e" :size="24" /></view
            ><view class="copy"
                ><text class="heading">{{ t(pending ? 'Under review' : 'Complete identity verification') }}</text
                ><text class="description">{{
                    t(pending ? 'Your identity verification is under review.' : 'Verify your identity before using financial services.')
                }}</text></view
            ><view class="actions"
                ><button class="verify" @click="go('/kyc')">{{ t(pending ? 'Under review' : 'Verify now') }}</button
                ><button class="cancel" @click="emit('close')">{{ t('Cancel') }}</button></view
            ></view
        ></view
    >
</template>
<style scoped>
.identity-overlay {
    position: fixed;
    inset: 0;
    z-index: 110;
    background: #0008;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
}
.identity-dialog {
    position: relative;
    background: white;
    border: 1px solid #e2e7e4;
    border-radius: 16px;
    padding: 24px;
    width: 100%;
    max-width: 384px;
    display: flex;
    flex-direction: column;
    gap: 16px;
    box-shadow: 0 10px 15px -3px #0002;
}
.close {
    position: absolute;
    right: 16px;
    top: 16px;
    background: none;
    padding: 0;
    line-height: normal;
    width: 16px;
    height: 16px;
}
.symbol {
    margin-bottom: 8px;
    width: 48px;
    height: 48px;
    display: flex;
    align-items: center;
    justify-content: center;
    background: #fef3c7;
    border-radius: 999px;
}
.copy {
    text-align: left;
}
.heading {
    display: block;
    font-size: 18px;
    line-height: 18px;
    font-weight: 600;
}
.description {
    display: block;
    margin-top: 8px;
    font-size: 14px;
    line-height: 24px;
    color: #68736e;
}
.actions {
    display: flex;
    flex-direction: column;
    gap: 8px;
}
.actions button {
    min-height: 40px;
    padding: 8px 16px;
    font-size: 14px;
    line-height: 24px;
    border-radius: 6px;
    margin: 0;
}
.verify {
    background: #b45309;
    color: white;
}
.cancel {
    background: transparent;
    color: #171915;
}
@media (min-width: 640px) {
    .copy {
        text-align: left;
    }
}
</style>
