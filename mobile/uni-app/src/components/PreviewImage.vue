<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { photoUrl } from '../lib/api';
import { t } from '../lib/i18n';
const props = defineProps<{ src: string; sources?: string[]; mode?: 'aspectFit' | 'widthFix' | 'aspectFill'; preview?: boolean }>();
const candidates = computed(() => [...new Set([props.src, ...(props.sources ?? [])].filter(Boolean).map(value => photoUrl(value) || value))]);
const index = ref(0);
watch(() => JSON.stringify(candidates.value), () => { index.value = 0; });
function open() {
    const current = candidates.value[index.value];
    if (props.preview && current) uni.previewImage({ urls: [current], current });
}
</script>
<template>
    <image v-if="index < candidates.length" :key="candidates[index]" :src="candidates[index]" :mode="mode ?? 'aspectFit'" @error="index++" @click="open" />
    <text v-else role="button" @click.stop="index = 0">{{ t('Image failed to load. Click to retry.') }}</text>
</template>
