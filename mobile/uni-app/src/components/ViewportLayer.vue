<script setup lang="ts">
import { ref } from 'vue';
import { onHide, onShow } from '@dcloudio/uni-app';
defineOptions({ inheritAttrs: false });
// H5 keeps previous pages mounted during navigateTo. Body portals must follow the
// owning page's visibility, otherwise hidden pages leave clickable navigation behind.
const visible = ref(true);
onHide(() => {
    visible.value = false;
});
onShow(() => {
    visible.value = true;
});
</script>
<template>
    <!-- #ifdef H5 -->
    <Teleport to="body">
        <!-- #endif -->
        <view v-if="visible" v-bind="$attrs" class="viewport-layer"><slot /></view>
        <!-- #ifdef H5 -->
    </Teleport>
    <!-- #endif -->
</template>
<style scoped>
/* H5 portals no longer inherit the uni-page body's typography. */
.viewport-layer {
    color-scheme: light;
    color: #171c19;
    font-family:
        Inter,
        ui-sans-serif,
        system-ui,
        -apple-system,
        BlinkMacSystemFont,
        'Segoe UI',
        sans-serif;
    -webkit-font-smoothing: antialiased;
}
</style>
