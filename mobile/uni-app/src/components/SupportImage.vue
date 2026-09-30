<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue';
import { privateImage, photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
const props = defineProps<{ path: string }>();
const src = ref('');
const failed = ref(false);
let alive = true;
async function load() {
    failed.value = false;
    try {
        const file = /^https:\/\//i.test(props.path) ? photoUrl(props.path) : await privateImage(props.path);
        if (alive) src.value = file;
    } catch {
        failed.value = true;
    }
}
function preview() {
    uni.previewImage({ urls: [src.value], current: src.value });
}
onMounted(load);
onUnmounted(() => {
    alive = false;
    // #ifdef H5
    if (src.value.startsWith('blob:')) URL.revokeObjectURL(src.value);
    // #endif
});
</script>
<template>
    <image
        v-if="src"
        :src="src"
        mode="widthFix"
        style="width: 220px; max-width: 100%; max-height: 220px; display: block; border-radius: 8px"
        @click="preview"
    /><button v-else-if="failed" class="secondary" @click="load">{{ t('Retry') }}</button
    ><text v-else class="muted">…</text>
</template>
