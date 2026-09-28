<script setup lang="ts">
import UiIcon from './UiIcon.vue';
import { t } from '../lib/i18n';
defineProps<{
    open: boolean;
    title: string;
    description?: string;
    busy?: boolean;
    sheet?: boolean;
    wide?: boolean;
}>();
const emit = defineEmits<{ close: [] }>();
</script>
<template>
    <view v-if="open" class="modal-backdrop" :class="{ sheet }" @click.self="!busy && emit('close')"
        ><view
            class="modal-panel"
            :class="{ wide }"
            role="dialog"
            aria-modal="true"
            :aria-label="title"
            ><view class="modal-header"
                ><text>{{ title }}</text
                ><button
                    class="icon"
                    :disabled="busy"
                    :aria-label="t('Close')"
                    @click="emit('close')"
                >
                    <UiIcon name="x" :size="20" /></button></view
            ><text v-if="description" class="modal-description">{{ description }}</text
            ><scroll-view scroll-y class="modal-scroll"><slot /></scroll-view></view
    ></view>
</template>
<style scoped>
.modal-backdrop {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: #0008;
}
.modal-panel {
    width: 100%;
    max-width: 448px;
    padding: 24px;
    border: 1px solid #e2e7e4;
    background: #f7f6f0;
    border-radius: 12px;
    box-shadow: 0 20px 60px #0002;
    max-height: 90vh;
    display: flex;
    flex-direction: column;
}
.modal-panel.wide {
    max-width: 672px;
}
.modal-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
    font-size: 18px;
    font-weight: 600;
    line-height: 24px;
    margin-bottom: 8px;
    flex-shrink: 0;
}
.modal-header .icon {
    width: 28px;
    height: 28px;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: normal;
}
.modal-description {
    display: block;
    font-size: 14px;
    color: #68736e;
    line-height: 1.5;
    margin-bottom: 16px;
    flex-shrink: 0;
}
.modal-scroll {
    max-height: calc(90vh - 180px);
    min-height: 0;
    flex: 1;
}
.sheet {
    align-items: flex-end;
    padding: 0;
}
.sheet .modal-panel {
    border-radius: 24px 24px 0 0;
    max-width: 512px;
    padding-bottom: calc(24px + env(safe-area-inset-bottom));
}
</style>
