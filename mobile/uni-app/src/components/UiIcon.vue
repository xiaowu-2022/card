<script setup lang="ts">
import { computed } from 'vue';
import icons from '../generated/icons.json';
import { staticAsset } from '../lib/origin';
const props = withDefaults(defineProps<{ name: string; size?: number; color?: string }>(), {
    size: 26,
});
const source = computed(() => {
    const svg = (icons as Record<string, string>)[props.name];
    return props.color && /^#[0-9a-f]{6}$/i.test(props.color) && svg
        ? 'data:image/svg+xml;charset=utf-8,' +
              encodeURIComponent(
                  svg.replace(/stroke="#[0-9a-f]{6}"/gi, 'stroke="' + props.color + '"'),
              )
        : staticAsset('icons/' + props.name + '.svg');
});
</script>
<template>
    <image
        :src="source"
        mode="aspectFit"
        :style="{ width: size + 'px', height: size + 'px', verticalAlign: 'middle', flexShrink: 0 }"
    />
</template>
