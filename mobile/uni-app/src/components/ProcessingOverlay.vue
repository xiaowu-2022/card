<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { t } from '../lib/i18n';
const props = defineProps<{ open: boolean; message: string }>();
const elapsed = ref(0);
const elapsedLabel = computed(() => t('Waiting {{seconds}}s', { seconds: elapsed.value }));
let timer: ReturnType<typeof setInterval> | undefined;
function stop() { if (timer) clearInterval(timer); timer = undefined; }
watch(() => props.open, (open) => {
    stop();
    elapsed.value = 0;
    if (open) {
        const started = Date.now();
        timer = setInterval(() => { elapsed.value = Math.floor((Date.now() - started) / 1000); }, 1000);
    }
}, { immediate: true });
onBeforeUnmount(stop);
</script>
<template>
    <view v-if="open" class="processing-overlay" @touchmove.stop.prevent @click.stop role="dialog" aria-modal="true" :aria-label="message">
        <view class="processing-panel" role="status" aria-live="polite">
            <view class="processing-spinner" />
            <text class="processing-message">{{ message }}</text>
            <text>{{ elapsedLabel }}</text>
            <text v-if="elapsed >= 15">{{ t('Processing is taking longer. Please do not submit again.') }}</text>
        </view>
    </view>
</template>
<style scoped>
.processing-overlay { position: fixed; inset: 0; z-index: 10000; background: #0008; display: flex; align-items: center; justify-content: center; padding: 24px; }
.processing-panel { width: 100%; max-width: 340px; border-radius: 18px; background: #fff; padding: 28px 20px; display: flex; flex-direction: column; align-items: center; gap: 14px; text-align: center; color: #163e34; font-size: 14px; }
.processing-message { font-weight: 600; font-size: 17px; }
.processing-spinner { width: 32px; height: 32px; border: 3px solid #dde7e3; border-top-color: #163e34; border-radius: 50%; animation: processing-spin .9s linear infinite; }
@keyframes processing-spin { to { transform: rotate(360deg); } }
</style>
