<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
const props = defineProps<{ sources: string[]; label: string }>();
const emit = defineEmits<{ refresh: []; open: [] }>();
const candidates = computed(() => [
    ...new Set(props.sources.filter(Boolean).map((value) => photoUrl(value) || value)),
]);
const index = ref(0),
    attempt = ref(0),
    loaded = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
const current = computed(() => candidates.value[index.value]);
function failed(run: number, position: number) {
    if (run !== attempt.value || position !== index.value) return;
    clearTimeout(timer);
    loaded.value = false;
    index.value++;
}
function complete(run: number, position: number) {
    if (run !== attempt.value || position !== index.value) return;
    clearTimeout(timer);
    loaded.value = true;
}
const image = computed(() => {
    const run = attempt.value,
        position = index.value;
    return {
        src: current.value,
        key: `${run}:${position}`,
        failed: () => failed(run, position),
        complete: () => complete(run, position),
    };
});
function reset() {
    clearTimeout(timer);
    attempt.value++;
    index.value = 0;
    loaded.value = false;
}
watch(() => JSON.stringify(candidates.value), reset);
watch(
    () => image.value.key + image.value.src,
    () => {
        clearTimeout(timer);
        loaded.value = false;
        const run = attempt.value,
            position = index.value;
        if (current.value) timer = setTimeout(() => failed(run, position), 15000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearTimeout(timer));
function refresh() {
    reset();
    emit('refresh');
}
</script>
<template>
    <view class="retry-image">
        <view class="image-frame" @click="loaded && emit('open')">
            <image
                v-if="image.src"
                :key="image.key"
                :src="image.src"
                mode="aspectFit"
                :aria-label="label"
                :style="{ opacity: loaded ? 1 : 0 }"
                @load="image.complete"
                @error="image.failed"
            />
            <text v-if="!loaded" class="image-status">{{
                t(current ? 'Loading…' : 'Image unavailable')
            }}</text>
        </view>
        <button class="image-refresh" @click.stop="refresh">{{ t('Refresh image') }}</button>
    </view>
</template>
<style scoped>
.retry-image {
    width: 100%;
}
.image-frame {
    position: relative;
    width: 100%;
    height: var(--document-image-height, 112px);
    background: #f0f2ef;
    border: 1px solid #e2e7e4;
    border-radius: 10px;
    overflow: hidden;
}
.image-frame image {
    display: block;
    width: 100%;
    height: 100%;
}
.image-status {
    position: absolute;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 12px;
    text-align: center;
    font-size: 13px;
    color: #68736e;
}
.image-refresh {
    margin: 8px 0 0;
    padding: 4px 12px;
    font-size: 13px;
    line-height: 24px;
    background: #eef3ef;
    color: #275e48;
}
</style>
