<script setup lang="ts">
defineProps<{ message: string }>();
</script>
<template>
    <!-- #ifdef H5 -->
    <Teleport to="body">
    <!-- #endif -->
        <view v-if="message" class="light-tip-layer" role="status" aria-live="polite" aria-atomic="true">
            <text class="light-tip">{{ message }}</text>
        </view>
    <!-- #ifdef H5 -->
    </Teleport>
    <!-- #endif -->
</template>
<style scoped>
.light-tip-layer { position: fixed; inset: 0; z-index: 20000; pointer-events: none; display: flex; align-items: center; justify-content: center; padding: 24px; box-sizing: border-box; }
.light-tip { max-width: 300px; padding: 12px 20px; border-radius: 12px; background: rgba(24, 30, 27, .94); color: #fff; font-size: 14px; line-height: 22px; text-align: center; box-shadow: 0 8px 28px #0002; }
</style>
