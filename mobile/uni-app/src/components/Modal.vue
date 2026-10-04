<script setup lang="ts">
import { computed, ref, watch, onBeforeUnmount } from 'vue';
import { onHide, onShow } from '@dcloudio/uni-app';
import { lockModalPage, modalViewportStyle } from '../lib/modal-viewport';
import UiIcon from './UiIcon.vue';
import { t } from '../lib/i18n';
const props = defineProps<{
    open: boolean;
    title: string;
    description?: string;
    busy?: boolean;
    sheet?: boolean;
    wide?: boolean;
}>();
const emit = defineEmits<{ close: [] }>();
const pageVisible = ref(true);
onHide(() => { pageVisible.value = false; });
onShow(() => { pageVisible.value = true; });
const visible = computed(() => props.open && pageVisible.value);
const viewportStyle = ref<Record<string, string>>({});
// #ifdef H5
let stop: (() => void) | undefined;
watch(visible, open => {
    stop?.(); stop = undefined;
    if (!open) return;
    const unlock = lockModalPage();
    const update = () => { viewportStyle.value = modalViewportStyle(); };
    update();
    window.visualViewport?.addEventListener('resize', update);
    window.visualViewport?.addEventListener('scroll', update);
    window.addEventListener('resize', update);
    stop = () => {
        window.visualViewport?.removeEventListener('resize', update);
        window.visualViewport?.removeEventListener('scroll', update);
        window.removeEventListener('resize', update);
        unlock();
    };
}, { immediate: true });
onBeforeUnmount(() => stop?.());
// #endif
</script>
<template>
    <!-- #ifdef H5 -->
    <Teleport to="body">
    <!-- #endif -->
    <view v-if="visible" class="modal-backdrop" :style="viewportStyle" :class="{ sheet }" @click.self="!busy && emit('close')"
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
            ><!-- #ifdef H5 -->
            <component :is="'div'" class="modal-scroll"><slot /></component>
            <!-- #endif -->
            <!-- #ifndef H5 -->
            <scroll-view scroll-y class="modal-scroll"><slot /></scroll-view>
            <!-- #endif --></view
    ></view>
    <!-- #ifdef H5 -->
    </Teleport>
    <!-- #endif -->
</template>
<style scoped>
.modal-backdrop {
    position: fixed;
    top: var(--modal-top, 0px);
    left: var(--modal-left, 0px);
    width: var(--modal-width, 100%);
    height: var(--modal-height, 100vh);
    box-sizing: border-box;
    z-index: 1000;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 16px;
    background: #0008;
    color: #171c19;
    font-size: 16px;
    font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
}
.modal-panel {
    width: 100%;
    max-width: 448px;
    padding: 24px;
    border: 1px solid #e2e7e4;
    background: #f7f6f0;
    border-radius: 12px;
    box-shadow: 0 20px 60px #0002;
    max-height: 100%;
    min-height: 0;
    box-sizing: border-box;
    overflow: hidden;
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
    max-height: calc(var(--modal-height, 100vh) - 150px);
    min-height: 0;
    flex: 0 1 auto;
    overflow-y: auto;
    overscroll-behavior: contain;
    -webkit-overflow-scrolling: touch;
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
