<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../lib/i18n';
const props = withDefaults(
    defineProps<{
        modelValue: string;
        label: string;
        maxMb?: number;
        disabled?: boolean;
        jpegPngOnly?: boolean;
    }>(),
    { maxMb: 10 },
);
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const hint = computed(() =>
    props.jpegPngOnly
        ? t(
              'PNG or JPEG, up to 6 MB per document. Only submit documents you are authorized to use.',
          )
        : t('JPEG, PNG or WEBP · max {{value1}} MB', { value1: props.maxMb }),
);
function choose() {
    if (props.disabled) return;
    uni.chooseImage({
        count: 1,
        sizeType: ['original'],
        sourceType: ['album', 'camera'],
        success(result) {
            const files = Array.isArray(result.tempFiles) ? result.tempFiles : [];
            if (files[0] && files[0].size > props.maxMb * 1024 * 1024) {
                uni.showToast({ title: hint.value, icon: 'none' });
                return;
            }
            emit('update:modelValue', result.tempFilePaths[0] ?? '');
        },
    });
}
</script>
<template>
    <view class="upload-field"
        ><text class="upload-label">{{ label }}</text
        ><button class="upload-control" :disabled="disabled" @click="choose">
            <image
                v-if="modelValue"
                :src="modelValue"
                class="upload-preview"
                mode="aspectFit"
            /><text>{{ t(modelValue ? 'Change image' : 'Choose file') }}</text></button
        ><text class="upload-hint">{{ hint }}</text></view
    >
</template>
<style scoped>
.upload-field {
    margin-bottom: 20px;
}
.upload-label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    margin-bottom: 8px;
}
.upload-control {
    display: flex;
    align-items: center;
    gap: 12px;
    min-height: 48px;
    padding: 10px 12px;
    border: 1px solid #e2e7e4;
    border-radius: 12px;
    background: white;
    font-size: 16px;
    text-align: left;
    line-height: 24px;
}
.upload-preview {
    width: 48px;
    height: 40px;
}
.upload-hint {
    display: block;
    margin-top: 6px;
    font-size: 14px;
    color: #68736e;
    line-height: 1.6;
}
</style>
