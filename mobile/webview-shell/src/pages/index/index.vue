<script setup lang="ts">
import { ref } from 'vue';
import { onReady, onShow, onHide, onUnload, onBackPress } from '@dcloudio/uni-app';
import config from '../../config.json';
import { discover, type DirectoryCache } from '../../lib/directory';
import { readinessScript } from '../../lib/readiness';
import { safeAddress, debugScript, debugMessages } from '../../lib/debug';
type Webview = {
    append: (child: Webview) => void; close: () => void;
    getURL: () => string; evalJS: (script: string) => void; setStyle: (styles: Record<string, unknown>) => void;
    loadURL: (url: string) => void; reload: () => void; back: () => void;
    canBack: (callback: (result: { canBack: boolean }) => void) => void;
    addEventListener: (event: string, callback: (event: { title?: string }) => void) => void;
};
declare const plus: { webview: { create: (url: string, id: string, styles: Record<string, unknown>) => Webview }; runtime: { quit: () => void } };
const state = ref<'discovering' | 'loading' | 'ready' | 'error'>('discovering');
const error = ref(''), active = ref('');
const debugEnabled = ref(false), debugRows = ref<string[]>([]), debugUrl = ref('');
const debugHeight = Math.min(300, Math.floor((uni.getSystemInfoSync().screenHeight || 800) * 0.4));
let debugTimer: ReturnType<typeof setTimeout> | undefined, debugLease: ReturnType<typeof setTimeout> | undefined;
let debugGeneration = 0, debugPrefix = '', debugInstalled = false, background = false;
const statusHeight = uni.getSystemInfoSync().statusBarHeight ?? 24;
let child: Webview | null = null, timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false, detecting = false, lastRefresh = 0;
let readinessTimer: ReturnType<typeof setTimeout> | undefined, readinessToken = '', loadSequence = 0;
function debugLog(message: string) {
    if (debugEnabled.value) debugRows.value = [...debugRows.value.slice(-59), `${new Date().toLocaleTimeString()} ${message}`];
}
function installDebug() {
    if (!debugEnabled.value || !child || debugInstalled || !/^https:\/\//i.test(child.getURL())) return;
    debugPrefix = `specpay-debug-${Date.now()}-${debugGeneration}:`;
    child.evalJS(debugScript(debugPrefix, true)); debugInstalled = true;
}
function setDebug(enabled: boolean) {
    const changed = debugEnabled.value !== enabled;
    debugEnabled.value = enabled;
    if (!enabled) {
        if (child) child.evalJS(debugScript('', false));
        debugInstalled = false; debugPrefix = ''; debugRows.value = []; debugUrl.value = '';
    } else {
        debugUrl.value = safeAddress(child?.getURL() || active.value);
        if (changed) debugLog('调试已开启（仅本机内存，最多 60 条）');
        installDebug();
    }
    if (changed) resizeContent();
}
function stopDebug() {
    ++debugGeneration; clearTimeout(debugTimer); clearTimeout(debugLease); setDebug(false);
}
function refreshDebug() {
    clearTimeout(debugTimer);
    if (disposed || background || !active.value) return;
    const generation = ++debugGeneration, host = active.value, tenantId = cache()?.tenantId;
    let done = false;
    const finish = (enabled: boolean) => {
        if (done || disposed || background || generation !== debugGeneration || active.value !== host) return;
        done = true; clearTimeout(timeout); clearTimeout(debugLease); setDebug(enabled);
        // A lost callback / suspended connection must not leave diagnostics enabled forever.
        debugLease = setTimeout(() => setDebug(false), 15000);
        debugTimer = setTimeout(refreshDebug, 10000);
    };
    const timeout = setTimeout(() => finish(false), 3000);
    uni.request({url: host + '/api/mobile/v1/app-debug', method: 'GET', timeout: 2500,
        header: {Accept: 'application/json'}, withCredentials: false,
        success: response => {
            const data = response.data as {tenantId?: string; tenantSlug?: string; enabled?: boolean};
            finish(response.statusCode === 200 && !!tenantId && data?.tenantId === tenantId
                && data?.tenantSlug === config.tenantSlug && data?.enabled === true);
        }, fail: () => finish(false)});
}
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
function stopTimer() {
    clearTimeout(timer); timer = undefined;
    clearTimeout(readinessTimer); readinessTimer = undefined;
    readinessToken = '';
}
function failure(message: string) {
    if (disposed) return;
    debugLog('加载失败：' + message);
    stopTimer();
    const previous = child; child = null; previous?.close();
    error.value = message; state.value = 'error';
}
function contentBounds() { return { top: `${statusHeight}px`, bottom: debugEnabled.value ? `${debugHeight}px` : '0px', left: '0px', width: '100%' }; }
function loading() {
    stopTimer(); state.value = 'loading'; error.value = '';
    debugInstalled = false; debugPrefix = ''; debugLog('网页开始加载');
    readinessToken = `specpay-ready-${Date.now()}-${++loadSequence}`;
    child?.setStyle({ opacity: 0 });
    timer = setTimeout(() => failure('网页未能显示，请重试或重新连接。'), 30000);
}
function resizeContent() { child?.setStyle(contentBounds()); }
function checkContent(view: Webview) {
    if (disposed || child !== view || state.value !== 'loading') return;
    clearTimeout(readinessTimer);
    // Only a layout/readiness boolean crosses via the title event. No native
    // bridge, input values, cookies or business requests are exposed or replayed.
    view.evalJS(readinessScript(readinessToken));
    readinessTimer = setTimeout(() => checkContent(view), 350);
}
function open(url: string) {
    // #ifdef APP-PLUS
    child?.close(); child = null;
    const page = getCurrentPages().slice(-1)[0] as unknown as { $getAppWebview: () => Webview };
    const view = plus.webview.create('', 'specpay-h5', {
        ...contentBounds(), opacity: 0, render: 'always', background: '#11100c',
        // Run the deployed H5 as a normal webpage, without injecting native privileges.
        plusrequire: 'none', popGesture: 'none',
        progress: { color: '#d6bb74' },
    });
    child = view; debugInstalled = false;
    view.addEventListener('loading', () => { if (child === view) loading(); });
    view.addEventListener('rendering', () => { if (child === view) installDebug(); });
    view.addEventListener('loaded', () => {
        if (disposed || child !== view || state.value === 'error') return;
        // An empty native window can emit loaded before the requested document.
        if (!/^https:\/\//i.test(view.getURL())) return;
        if (debugEnabled.value) debugUrl.value = safeAddress(view.getURL());
        debugLog('文档 loaded，等待页面内容'); installDebug();
        checkContent(view);
    });
    view.addEventListener('titleUpdate', event => {
        if (!disposed && child === view && debugEnabled.value && debugPrefix && event.title?.startsWith(debugPrefix)) {
            debugMessages(event.title.slice(debugPrefix.length)).forEach(debugLog); return;
        }
        if (disposed || child !== view || state.value !== 'loading'
            || !readinessToken || event.title !== readinessToken
            || !/^https:\/\//i.test(view.getURL())) return;
        view.setStyle({ ...contentBounds(), opacity: 1 });
        stopTimer(); state.value = 'ready'; debugLog('内容已渲染，显示网页');
    });
    view.addEventListener('error', () => { if (child === view) failure('网页无法打开，请检查网络后重试。'); });
    page.$getAppWebview().append(view);
    loading(); view.loadURL(url + config.entryPath);
    // #endif
    // #ifndef APP-PLUS
    failure('请使用 HBuilderX 运行到手机或云打包，此工程是 App 网页容器。');
    // #endif
}
async function connect() {
    if (detecting || disposed) return;
    detecting = true; state.value = 'discovering'; error.value = ''; stopTimer();
    const previousChild = child; child = null; previousChild?.close();
    try {
        const result = await discover(config.seeds, config.tenantSlug, cache(), probe);
        if (disposed) return;
        save(result); lastRefresh = Date.now(); active.value = result.selected;
        stopDebug(); refreshDebug(); open(result.selected);
    } catch (e) { failure(e instanceof Error ? e.message : '连接失败，请重试。'); }
    finally { detecting = false; }
}
function retry() {
    return connect();
}
function changeLine() {
    if (detecting) return;
    uni.showModal({ title: '重新连接', content: '将重新连接服务并打开登录页，可能需要重新登录。请确认没有正在提交的操作。',
        confirmText: '继续', cancelText: '取消', success: result => { if (result.confirm) void connect(); } });
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
onReady(() => { uni.onWindowResize(resizeContent); void connect(); });
onShow(() => {
    background = false; refreshDebug();
    // Refresh the directory cache without moving an active login or replaying an operation.
    if (!child || detecting || Date.now() - lastRefresh < 60000) return;
    lastRefresh = Date.now();
    void discover(config.seeds, config.tenantSlug, cache(), probe).then(value => {
        if (!disposed) save({ ...value, selected: active.value });
    }).catch(() => { /* Retain the last verified directory during a temporary outage. */ });
});
onHide(() => { background = true; stopDebug(); });
onBackPress(() => { back(); return true; });
onUnload(() => { disposed = true; stopDebug(); uni.offWindowResize(resizeContent); stopTimer(); child?.close(); child = null; });
</script>
<template>
    <view class="shell" :style="{ paddingTop: statusHeight + 'px', paddingBottom: debugEnabled ? debugHeight + 'px' : '0px' }">
        <view v-if="debugEnabled" class="debug-panel" :style="{ height: debugHeight + 'px' }">
            <text class="debug-title">App 调试 · 2.5.07 · {{ state }}</text>
            <text class="debug-address">{{ debugUrl }}</text>
            <scroll-view scroll-y class="debug-log"><text v-for="(row, index) in debugRows" :key="index" class="debug-row">{{ row }}</text></scroll-view>
        </view>
        <view v-if="state !== 'ready'" class="welcome" :style="{ minHeight: `calc(100vh - ${statusHeight}px)` }">
            <view class="masthead">
                <view class="brand-mark"><view class="circle red"/><view class="circle gold"/></view>
                <view class="wordmark"><text class="brand-name">Spec Pay</text><text class="brand-caption">万事达 U卡</text></view>
            </view>
            <view class="hero">
                <text class="eyebrow">SPEC PAY · GLOBAL PAYMENT</text>
                <text class="headline">Spec Pay 万事达U卡</text>
                <text class="tagline">全球支付，尽在掌握。</text>
                <image class="card-art" src="/static/brand/spec-pay-card.png" mode="widthFix" accessibility-label="Spec Pay 黑金卡片外观示意" />
                <text class="card-caption">卡片外观示意</text>
            </view>
            <view class="connection">
                <view v-if="state === 'error'" class="error">
                    <text class="error-title">暂时无法连接</text>
                    <text class="error-message">{{ error }}</text>
                    <text v-if="active" class="error-message">{{ active }}</text>
                    <button class="primary" @click="retry">重试</button>
                    <button class="secondary" @click="changeLine">重新连接</button>
                </view>
                <view v-else class="loading-state" role="status">
                    <view class="loading-track"><view class="loading-glow"/></view>
                    <text class="loading-label">{{ state === 'discovering' ? '正在检测线路速度，选择最快线路' : '正在为您开启全球支付' }}</text>
                </view>
            </view>
            <text class="security-note">保护账户安全，请勿向任何人透露密码或验证码。</text>
        </view>
    </view>
</template>
<style scoped>
.debug-panel { position: fixed; z-index: 100; bottom: 0; left: 0; right: 0; padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); background: #171717; border-top: 1px solid #d6bb74; color: #eee; display: flex; flex-direction: column; }
.debug-title { font-size: 13px; color: #e2c984; }.debug-address { font-size: 11px; overflow-wrap: anywhere; margin: 6px 0; }.debug-log { flex: 1; min-height: 0; }.debug-row { display: block; font: 11px/1.6 monospace; overflow-wrap: anywhere; }
.shell { min-height: 100vh; box-sizing: border-box; background: #11100c; color: #f6edce; }
.welcome { min-height: calc(100vh - 24px); box-sizing: border-box; display: flex; flex-direction: column; background: radial-gradient(ellipse at 95% 35%, #342915 0%, #17150f 43%, #11100c 75%); padding: 30px 24px 24px; }
.masthead { display: flex; align-items: center; gap: 10px; }
.brand-mark { position: relative; width: 48px; height: 30px; }
.circle { position: absolute; width: 30px; height: 30px; border-radius: 50%; top: 0; }.red { left: 0; background: #eb1726; }.gold { right: 0; background: #f5ae20; opacity: .9; }
.wordmark { display: flex; flex-direction: column; }.brand-name { font-size: 19px; font-weight: 700; letter-spacing: -.5px; }.brand-caption { font-size: 10px; letter-spacing: 2px; color: #d7bf80; margin-top: 2px; }
.hero { text-align: center; padding-top: 64px; }
.eyebrow { display: block; font-size: 9px; letter-spacing: 3px; color: #b49b60; }
.headline { display: block; font-size: clamp(23px, 6.5vw, 36px); font-weight: 700; letter-spacing: -.8px; margin-top: 20px; white-space: nowrap; }
.tagline { display: block; font-size: 17px; color: #cec5ad; margin-top: 18px; letter-spacing: 1px; }
.card-art { display: block; width: 100%; margin: 36px auto 0; max-width: 540px; }
.card-caption { display: block; font-size: 10px; color: #a29477; margin-top: 12px; letter-spacing: 2px; }
.connection { width: 100%; max-width: 360px; margin: auto; padding: 48px 0 36px; }
.loading-track { width: 100px; height: 2px; margin: 0 auto; background: #423820; overflow: hidden; border-radius: 2px; }
.loading-glow { width: 45px; height: 100%; background: linear-gradient(90deg, #80692c, #f6e2a3); animation: glide 1.8s ease-in-out infinite alternate; }
.loading-label { display: block; text-align: center; font-size: 12px; color: #c1ad7c; letter-spacing: 2px; margin-top: 20px; }
.security-note { display: block; text-align: center; font-size: 10px; line-height: 1.8; color: #827963; padding-bottom: env(safe-area-inset-bottom); }
.error { text-align: center; }.error-title { display: block; font-size: 19px; }.error-message { display: block; font-size: 13px; color: #c4b89b; line-height: 1.7; margin: 12px 0 20px; }
.error button { font-size: 15px; border-radius: 28px; line-height: 46px; }
.primary { color: #201a0b; background: linear-gradient(110deg, #f1dda5, #c2a35a); }.secondary { color: #d8c28c; background: transparent; margin-top: 8px; }
@keyframes glide { from { transform: translateX(-25px); } to { transform: translateX(85px); } }
@media (max-height: 680px) { .hero { padding-top: 34px; }.card-art { margin-top: 24px; }.connection { padding-top: 30px; padding-bottom: 24px; } }
@media (prefers-reduced-motion: reduce) { .loading-glow { animation: none; width: 100%; } }
</style>
