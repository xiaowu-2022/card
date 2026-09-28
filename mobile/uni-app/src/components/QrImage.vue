<script setup lang="ts">
import { computed } from 'vue';
import qrcode from 'qrcode-generator';
const props = withDefaults(defineProps<{ value: string; size?: number }>(), { size: 176 });
const source = computed(() => {
    const qr = qrcode(0, 'M');
    qr.addData(props.value, 'Byte');
    qr.make();
    return qr.createDataURL(6, 24);
});
</script>
<template>
    <image
        :src="source"
        mode="aspectFit"
        :style="{ width: size + 'px', height: size + 'px' }"
        :aria-label="value"
    />
</template>
