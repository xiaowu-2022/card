<script setup lang="ts">
import { ref } from 'vue';
import RetryImage from './RetryImage.vue';
import Modal from './Modal.vue';
import { t } from '../lib/i18n';
import { useSensitiveScreen } from '../lib/sensitive';
defineProps<{ label: string; thumbnails: string[]; originals: string[] }>();
const emit = defineEmits<{ refresh: [] }>();
const open = ref(false);
useSensitiveScreen(() => {
    open.value = false;
});
</script>
<template>
    <view class="document-photo">
        <RetryImage
            :sources="thumbnails"
            :label="label"
            @open="open = true"
            @refresh="emit('refresh')"
        />
        <button class="document-open" :disabled="!originals.length" @click="open = true">
            {{ t('View full image') }}
        </button>
        <Modal :open="open" :title="label" wide @close="open = false">
            <view v-if="open" class="document-full">
                <RetryImage :sources="originals" :label="label" @refresh="emit('refresh')" />
            </view>
        </Modal>
    </view>
</template>
<style scoped>
.document-open {
    padding: 4px 8px;
    margin-top: 4px;
    background: transparent;
    color: #275e48;
    font-size: 13px;
    line-height: 24px;
}
.document-full {
    --document-image-height: min(55vh, 600px);
}
</style>
