<script setup lang="ts">
import { ref } from 'vue';
import { onReady, onShow, onUnload, onBackPress } from '@dcloudio/uni-app';
import config from '../../config.json';
import { discover, type DirectoryCache } from '../../lib/directory';
type Webview = {
    append: (child: Webview) => void; show: () => void; hide: () => void; close: () => void;
    loadURL: (url: string) => void; reload: () => void; back: () => void;
    canBack: (callback: (result: { canBack: boolean }) => void) => void;
    addEventListener: (event: string, callback: () => void) => void;
};
declare const plus: { webview: { create: (url: string, id: string, styles: Record<string, unknown>) => Webview }; runtime: { quit: () => void } };
const state = ref<'discovering' | 'loading' | 'ready' | 'error'>('discovering');
const error = ref(''), active = ref('');
const statusHeight = uni.getSystemInfoSync().statusBarHeight ?? 24;
let child: Webview | null = null, timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false, detecting = false, lastRefresh = 0;
function cache(): DirectoryCache | null {
    try { const value = uni.getStorageSync(config.cacheKey); return value && typeof value === 'object' ? value : null; }
    catch { return null; }
}
function save(value: DirectoryCache) { try { uni.setStorageSync(config.cacheKey, value); } catch { /* optional cache */ } }
function probe(url: string): Promise<unknown> {
    return new Promise((resolve, reject) => uni.request({
        url: url + '/api/mobile/v1/domains', method: 'GET', timeout: 4000,
        header: { Accept: 'application/json' }, withCredentials: false,
        success: response => response.statusCode === 200 ? resolve(response.data) : reject(new Error('unavailable')),
        fail: reject,
    }));
}
function stopTimer() { clearTimeout(timer); timer = undefined; }
function failure(message: string) {
    if (disposed) return;
    stopTimer(); child?.hide(); error.value = message; state.value = 'error';
}
function loading() {
    stopTimer(); state.value = 'loading'; error.value = '';
    timer = setTimeout(() => failure('网页加载超时，请重试。当前线路保持不变。'), 30000);
}
function open(url: string) {
    // #ifdef APP-PLUS
    child?.close(); child = null;
    const page = getCurrentPages().slice(-1)[0] as unknown as { $getAppWebview: () => Webview };
    const view = plus.webview.create('', 'specpay-h5', {
        top: `${statusHeight + 48}px`, bottom: '0px', background: '#f7f6f0',
        // Run the deployed H5 as a normal webpage, without injecting native privileges.
        plusrequire: 'none', popGesture: 'none',
        progress: { color: '#39ad8d' },
    });
    child = view;
    view.addEventListener('loading', () => { if (child === view) loading(); });
    view.addEventListener('loaded', () => {
        if (disposed || child !== view) return;
        stopTimer(); state.value = 'ready'; view.show();
    });
    view.addEventListener('error', () => { if (child === view) failure('网页无法打开，请检查网络后重试。'); });
    page.$getAppWebview().append(view);
    view.hide(); loading(); view.loadURL(url + config.entryPath);
    // #endif
    // #ifndef APP-PLUS
    failure('请使用 HBuilderX 运行到手机或云打包，此工程是 App 网页容器。');
    // #endif
}
async function connect(reselect = false) {
    if (detecting || disposed) return;
    detecting = true; state.value = 'discovering'; error.value = ''; child?.hide(); stopTimer();
    try {
        const previous = cache();
        const result = await discover(config.seeds, config.tenantSlug,
            reselect && previous ? { ...previous, selected: '' } : previous, probe);
        if (disposed) return;
        save(result); lastRefresh = Date.now(); active.value = result.selected; open(result.selected);
    } catch (e) { failure(e instanceof Error ? e.message : '连接失败，请重试。'); }
    finally { detecting = false; }
}
function retry() {
    if (detecting) return;
    if (child && active.value) { loading(); child.reload(); }
    else void connect();
}
function changeLine() {
    if (detecting) return;
    uni.showModal({ title: '重新检测线路', content: '将重新打开登录页，切换域名后可能需要重新登录。请确认没有正在提交的操作。',
        confirmText: '继续', cancelText: '取消', success: result => { if (result.confirm) void connect(true); } });
}
function back() {
    if (!child) return;
    child.canBack(result => {
        if (result.canBack) child?.back();
        else uni.showModal({ title: '退出应用', content: '确定退出吗？', confirmText: '退出', cancelText: '取消', success: r => {
            // #ifdef APP-PLUS
            if (r.confirm) plus.runtime.quit();
            // #endif
        } });
    });
}
onReady(() => void connect());
onShow(() => {
    // Refresh the directory cache without moving an active login or replaying an operation.
    if (!child || detecting || Date.now() - lastRefresh < 60000) return;
    lastRefresh = Date.now();
    void discover(config.seeds, config.tenantSlug, cache(), probe).then(value => {
        if (!disposed) save({ ...value, selected: active.value });
    }).catch(() => { /* Retain the last verified directory during a temporary outage. */ });
});
onBackPress(() => { back(); return true; });
onUnload(() => { disposed = true; stopTimer(); child?.close(); child = null; });
</script>
<template>
    <view class="shell" :style="{ paddingTop: statusHeight + 'px' }">
        <view class="toolbar">
            <button @click="back" :disabled="!active">返回</button><text class="brand">U卡</text>
            <button @click="retry" :disabled="state === 'discovering'">刷新</button>
            <button @click="changeLine" :disabled="state === 'discovering'">线路</button>
        </view>
        <view class="content">
            <view v-if="state === 'error'" class="error">
                <text class="title">暂时无法打开</text><text>{{ error }}</text>
                <button class="primary" @click="retry">重新加载</button><button @click="changeLine">重新检测线路</button>
            </view>
            <view v-else-if="state !== 'ready'" class="skeleton">
                <text>{{ state === 'discovering' ? '正在连接可用线路…' : '正在加载页面…' }}</text>
                <view class="bar short"/><view class="card"/><view v-for="row in 3" :key="row" class="bar"/>
            </view>
        </view>
    </view>
</template>
<style scoped>
.shell { min-height: 100vh; background: #f7f6f0; }
.toolbar { height: 48px; display: flex; align-items: center; padding: 0 8px; border-bottom: 1px solid #e2e7e4; }
.toolbar button { margin: 0; padding: 0 12px; font-size: 14px; background: transparent; line-height: 44px; }
.brand { flex: 1; font-weight: 600; padding: 0 8px; }
.content { padding: 24px; }
.skeleton { color: #68736e; font-size: 14px; }
.bar,.card { background: #e0e7e2; border-radius: 12px; margin-top: 24px; height: 60px; }
.short { width: 45%; height: 24px; }.card { height: 170px; }
.error { padding-top: 48px; text-align: center; }.error text { display: block; margin-bottom: 20px; }
.title { font-size: 22px; font-weight: 600; }.error button { margin-top: 16px; font-size: 16px; }
.primary { background: #25241f; color: white; }
</style>
