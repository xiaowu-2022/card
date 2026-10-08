<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
const emit = defineEmits<{ close: [] }>();
const report = ref('');
const copied = ref(false);
const viewportMeta = document.querySelector<HTMLMetaElement>('meta[name="viewport"]');
const originalViewport = viewportMeta?.content ?? '';
let changedViewport = false;
function testViewport() {
    if (!viewportMeta) return;
    viewportMeta.content = originalViewport.split(',').map(part => part.trim())
        .filter(part => !/^viewport-fit\s*=/i.test(part)).concat('viewport-fit=auto').join(',');
    changedViewport = true;
    refresh();
}
function restoreViewport() {
    if (changedViewport && viewportMeta) viewportMeta.content = originalViewport;
    changedViewport = false;
    refresh();
}
const number = (value: number | undefined) => value === undefined ? null : Math.round(value * 100) / 100;
function refresh() {
    const viewport = window.visualViewport;
    const root = document.querySelector('.auth-root');
    const rect = root?.getBoundingClientRect();
    const probe = document.createElement('div');
    probe.style.cssText = 'position:fixed;left:0;top:0;width:0;height:100vh;visibility:hidden;pointer-events:none;padding-bottom:env(safe-area-inset-bottom,0px);box-sizing:border-box';
    document.body.appendChild(probe);
    const vh = probe.getBoundingClientRect().height;
    const safeBottom = getComputedStyle(probe).paddingBottom;
    probe.remove();
    // Layout and viewport configuration only. Never read fields, cookies, URLs or session data.
    report.value = JSON.stringify({
        diagnostic: 'login-layout-1',
        viewportFit: viewportMeta?.content.match(/viewport-fit\s*=\s*(auto|contain|cover)/i)?.[1] ?? 'auto',
        screen: [screen.width, screen.height, screen.availHeight, window.devicePixelRatio],
        window: [innerWidth, innerHeight],
        document: [document.documentElement.clientWidth, document.documentElement.clientHeight],
        visual: viewport ? [number(viewport.width), number(viewport.height), number(viewport.offsetTop), number(viewport.scale)] : null,
        scrollY: number(scrollY),
        vh: number(vh), safeBottom,
        login: rect ? [number(rect.top), number(rect.height), number(rect.bottom)] : null,
        backgrounds: [getComputedStyle(document.documentElement).backgroundColor, getComputedStyle(document.body).backgroundColor, root ? getComputedStyle(root).backgroundColor : null],
    }, null, 2);
    copied.value = false;
}
function copy() {
    uni.setClipboardData({ data: report.value, success: () => { copied.value = true; } });
}
onMounted(refresh);
// Reports change only on explicit Refresh, so opening this panel cannot create
// a resize/repaint loop or overwrite the pre-keyboard sample.
onBeforeUnmount(() => {
    if (changedViewport && viewportMeta) viewportMeta.content = originalViewport;
    report.value = '';
});
</script>
<template>
    <Teleport to="body">
        <view class="layout-diagnostics" role="dialog" aria-label="登录页布局诊断">
            <view class="diagnostic-actions">
                <button @click="refresh">刷新尺寸</button>
                <button @click="copy">{{ copied ? '已复制' : '复制尺寸' }}</button>
                <button @click="emit('close')">关闭</button>
            </view>
            <view class="diagnostic-actions">
                <button @click="testViewport">测试普通视口</button>
                <button @click="restoreViewport">恢复原设置</button>
            </view>
            <text class="diagnostic-report" selectable>{{ report }}</text>
        </view>
    </Teleport>
</template>
<style scoped>
.layout-diagnostics { position: fixed; top: 12px; left: 12px; right: 12px; z-index: 2000; max-height: 55vh; overflow-y: auto; background: #fff; color: #171c19; border: 2px solid #39ad8d; border-radius: 8px; padding: 8px; }
.diagnostic-actions { display: flex; }
.diagnostic-actions button { flex: 1; margin: 0 2px; padding: 4px; font-size: 12px; line-height: 24px; }
.diagnostic-report { display: block; white-space: pre-wrap; font: 11px/1.3 monospace; margin-top: 6px; }
</style>
