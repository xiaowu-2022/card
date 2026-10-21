<script setup lang="ts">
import { beginTiming, timingSnapshot } from '../../lib/startup-timing';
import { nextTick, ref } from 'vue';
import { onReady, onShow, onHide, onUnload, onBackPress } from '@dcloudio/uni-app';
import config from '../../config.json';
import manifest from '../../manifest.json';
import { discover, discoveryLimits, type DirectoryCache } from '../../lib/directory';
import { loginDiagnostic } from '../../lib/login-diagnostic';
import { readinessScript } from '../../lib/readiness';
import { safeAddress, debugScript, debugMessages } from '../../lib/debug';
import { pageOrigin, LoginRestoreUnavailable } from '../../lib/shared-session';
import { tokenSession, validToken, type Identity } from '../../lib/token-session';
import { iosCredentialVault, type IOSBridge } from '../../lib/ios-credentials';
import { sessionSignalScript } from '../../lib/session-signal';
import { posterBridgeScript, posterReceiver, savePoster, type PosterRuntime } from '../../lib/poster-bridge';
import { androidCredentialVault, type AndroidBridge } from '../../lib/device-credentials';
import { networkStartup, StartupCancelled } from '../../lib/network-startup';
import { startSessionDocument } from '../../lib/document-startup';
type Webview = {
    append: (child: Webview) => void; close: () => void;
    getURL: () => string | null; evalJS: (script: string) => void; setStyle: (styles: Record<string, unknown>) => void;
    loadURL: (url: string) => void; reload: () => void; back: () => void;
    canBack: (callback: (result: { canBack: boolean }) => void) => void;
    addEventListener: (event: string, callback: (event: { title?: string | null } | null | undefined) => void) => void;
};
declare const plus: PosterRuntime & { webview: { create: (url: string, id: string, styles: Record<string, unknown>) => Webview }; runtime: { appid: string; quit: () => void }; os: { name: string }; android: AndroidBridge; ios: IOSBridge; io: PosterRuntime['io'] & { convertLocalFileSystemURL(path: string): string } };
const state = ref<'discovering' | 'restoring' | 'loading' | 'ready' | 'error'>('discovering');
const webContentTimeoutMs = 60000;
const error = ref(''), active = ref(''), loginRecoveryFailed = ref(false);
const waitingForNetwork = ref(false), loginBootstrapReady = ref(false);
const startupCompleteKey = config.cacheKey + ':startup-complete';
let firstNetworkUse = true;
let hasCompletedStartup = false;
try {
    hasCompletedStartup = uni.getStorageSync(startupCompleteKey) === true;
    firstNetworkUse = !hasCompletedStartup && !cache()?.tenantId;
} catch { /* Optional startup marker. */ }
const networkRecovery = networkStartup({ firstUse: firstNetworkUse, waiting: value => { waitingForNetwork.value = value; } });
const debugEnabled = ref(false), debugRows = ref<string[]>([]), debugUrl = ref('');
const debugHeight = Math.min(300, Math.floor((uni.getSystemInfoSync().screenHeight || 800) * 0.4));
let debugTimer: ReturnType<typeof setTimeout> | undefined, debugLease: ReturnType<typeof setTimeout> | undefined;
let lastTimingVisible = 0;
let debugGeneration = 0, debugPrefix = '', debugInstalled = false, background = false;
const statusHeight = uni.getSystemInfoSync().statusBarHeight ?? 24;
let child: Webview | null = null, timer: ReturnType<typeof setTimeout> | undefined;
let disposed = false, detecting = false, lastRefresh = 0;
let directoryRefresh: Promise<DirectoryCache> | null = null;
let readinessTimer: ReturnType<typeof setTimeout> | undefined, readinessToken = '', loadSequence = 0;
let measuredParentHeight = 0, layoutRevision = 0;
let documentDeadline = 0;
let revealPending = false, startupPulseDone = false, startupPulseActive = false;
let startupPulseTimer: ReturnType<typeof setTimeout> | undefined;
let finishStartup: ReturnType<typeof beginTiming> | undefined, finishDocument: ReturnType<typeof beginTiming> | undefined, finishContent: ReturnType<typeof beginTiming> | undefined;
let loginStore: ReturnType<typeof tokenSession> | null = null, loginTenant = '', sessionPrefix = '', signedIn = false;
let loginDirectory: DirectoryCache | null = null;
let credentialRevision = 0;
let sessionSequence = 0, identityReady = false, expectsSignedIn = false;
let posterChannel: ReturnType<typeof posterReceiver> | null = null;
let preparedDocument = -1;
let startupShown = false;
let pendingStartupRetry = false, connectionUnavailable = false;
let startupRetryTimer: ReturnType<typeof setTimeout> | undefined;
const failedStartupOrigins = new Set<string>();
function retryStartupOrigin(url: string) {
    if (startupShown || disposed || !url || failedStartupOrigins.size >= 2) return false;
    if (failedStartupOrigins.has(url)) return true;
    failedStartupOrigins.add(url);
    stopTimer(); pendingStartupRetry = true;
    if (import.meta.env.DEV) console.info('[shell] 当前线路加载失败，保留凭证并验证备用线路');
    if (!detecting) startupRetryTimer = setTimeout(() => { if (!disposed) void connect(); }, 0);
    return true;
}
async function nativeApi<T>(url: string, path: string, token: string | null = null, channel?: string, revision?: number, timeoutMs = 10000): Promise<T> {
    const started = Date.now();
    return new Promise((resolve, reject) => {
        let settled = false;
        let task: { abort(): void } | undefined;
        const finish = (value?: T, status?: number, diagnostic?: string) => {
            if (settled) return;
            settled = true; clearTimeout(deadline);
            if (import.meta.env.DEV) console.info(`[shell] 接口 ${path}: ${status ?? diagnostic ?? 'failed'}, ${Date.now() - started}ms`);
            if (status === 200) resolve(value!);
            else { const error = new LoginRestoreUnavailable(); Object.assign(error, { status, diagnostic }); reject(error); }
        };
        const deadline = setTimeout(() => { finish(undefined, undefined, 'timeout'); try { task?.abort(); } catch {} }, timeoutMs);
        task = uni.request({
            url: url + '/api/mobile/v1' + path, method: path === '/session' ? 'GET' : 'POST',
            withCredentials: false, timeout: timeoutMs,
            header: { Accept: 'application/json', ...(token ? { Authorization: 'Bearer ' + token } : {}),
                ...(channel ? { 'X-Consumer-Bridge': channel } : {}) },
            data: revision ? { revision } : {},
            success: response => finish(response.data as T, response.statusCode), fail: () => finish(undefined, undefined, 'network'),
        });
    });
}
async function verifyToken(url: string, token: string | null): Promise<Identity> {
    type Session = { tenant?: { id?: string; slug?: string }; user?: { id?: string } | null; restricted?: boolean };
    let data: Session;
    const deadline = Date.now() + 10000;
    try { data = await nativeApi<Session>(url, '/session', token); }
    catch (error) {
        if (!token || (error as { status?: number }).status !== 401) throw error;
        // Confirm a valid same-company guest response before clearing expiry.
        data = await nativeApi<Session>(url, '/session', null, undefined, undefined, Math.max(1, deadline - Date.now()));
        if (data?.user !== null) throw Object.assign(new LoginRestoreUnavailable(), { diagnostic: 'identity' });
    }
    if (typeof data.tenant?.id !== 'string' || !data.tenant.id || data.tenant.slug !== config.tenantSlug
        || typeof data.restricted !== 'boolean' || (data.user !== null && (typeof data.user?.id !== 'string' || !data.user.id)))
        throw Object.assign(new LoginRestoreUnavailable(), { diagnostic: 'identity' });
    return { tenantId: data.tenant.id, signedIn: !!data.user, restricted: data.restricted };
}
async function configureLogin(directory: DirectoryCache, _previous: DirectoryCache | null) {
    if (loginTenant && loginTenant !== directory.tenantId) throw new Error('公司信息已变更，请重新打开应用。');
    if (!loginStore) {
        loginTenant = directory.tenantId;
        const scope = `webview-token:${plus.runtime.appid}:${config.tenantSlug}:${loginTenant}`;
        const key = 'encrypted-session:' + scope, metaKey = 'session-state:' + scope;
        let iosPath = '';
        if (plus.os.name !== 'Android') {
            try { iosPath = plus.io.convertLocalFileSystemURL('_doc/consumer-token-' + encodeURIComponent(loginTenant) + '.json'); }
            catch { throw Object.assign(new LoginRestoreUnavailable(), { storageCode: 'IOS-PATH-INVALID' }); }
            if (typeof iosPath !== 'string' || !iosPath.startsWith('/'))
                throw Object.assign(new LoginRestoreUnavailable(), { storageCode: 'IOS-PATH-INVALID' });
        }
        const vault = plus.os.name === 'Android' ? androidCredentialVault(plus.android, scope, {
            get: () => uni.getStorageSync(key), set: value => uni.setStorageSync(key, value), remove: () => uni.removeStorageSync(key),
        }, validToken) : iosCredentialVault(plus.ios,
            iosPath, scope);
        loginStore = tokenSession({ vault, verify: verifyToken,
            open: async url => (await nativeApi<{ channel: string }>(url, '/session/bridge/open')).channel,
            claim: async (url, channel, revision) => (await nativeApi<{ token: string }>(url, '/session/bridge/claim', null, channel, revision, 15000)).token,
            metadata: {
                get: () => { const value = uni.getStorageSync(metaKey); return value && typeof value === 'object' ? value : null; },
                set: value => uni.setStorageSync(metaKey, value),
            },
        });
    }
    loginDirectory = directory;
    await loginStore.configure(directory);
}
function trustedLoginPage(view: Webview) {
    return !!loginDirectory && pageOrigin(view.getURL()) === active.value && loginDirectory.origins.includes(active.value);
}
async function captureLogin() {
    const view = child, sequence = sessionSequence;
    if (signedIn && view && trustedLoginPage(view) && loginStore
        && !await loginStore.capture(active.value, credentialRevision,
            () => !disposed && child === view && signedIn && sessionSequence === sequence && trustedLoginPage(view)))
        throw new Error('登录凭证尚未保存');
}
async function confirmPageIdentity(view: Webview, sequence: number) {
    const finishTiming = beginTiming('identity');
    let lastFailure: unknown;
    for (let attempt = 0; attempt < 3; attempt++) {
        if (disposed || child !== view || sessionSequence !== sequence || !signedIn || !trustedLoginPage(view)) { finishTiming('error'); return; }
        try {
            await captureLogin();
            if (disposed || child !== view || sessionSequence !== sequence || !signedIn || !trustedLoginPage(view)) { finishTiming('error'); return; }
            identityReady = true; loginRecoveryFailed.value = false;
            finishTiming(); appendTimingRows();
            checkContent(view);
            return;
        } catch (error) {
            lastFailure = error;
            await new Promise(resolve => setTimeout(resolve, 250));
        }
    }
    finishTiming('error');
    if (!disposed && child === view && sessionSequence === sequence) {
        loginRecoveryFailed.value = true;
        const stage = loginStore?.captureStage();
        const reason = stage === 'storage' ? '本机安全存储未能保存登录凭证'
            : stage === 'verify' ? '登录凭证身份验证未完成' : '登录凭证领取未完成';
        failure(`${reason}（${loginDiagnostic(stage, lastFailure)}），请重试；当前登录页面和凭证将保留。`, true);
    }
}
function loginStorageFailure(error?: unknown, stage: string | undefined = 'storage') {
    debugLog('登录状态未能安全保存');
    uni.showToast({ title: `登录状态保存未完成（${loginDiagnostic(stage, error)}），请重试。`, icon: 'none', duration: 3500 });
}
function appendTimingRows() {
    if (!debugEnabled.value) return;
    for (const row of timingSnapshot()) {
        if (row.sequence <= lastTimingVisible) continue;
        lastTimingVisible = row.sequence;
        debugLog(`启动耗时 ${row.stage}: ${row.durationMs}ms (${row.outcome})`);
    }
}
function debugLog(message: string) {
    if (debugEnabled.value) debugRows.value = [...debugRows.value.slice(-59), `${new Date().toLocaleTimeString()} ${message}`];
}
function installDebug() {
    if (!debugEnabled.value || !child || debugInstalled || !pageOrigin(child.getURL())) return;
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
        if (changed) {
            debugLog('调试已开启（仅本机内存，最多 60 条）');
            lastTimingVisible = 0; appendTimingRows();
        }
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
function probe(url: string, onAbort?: (cancel: () => void) => void): Promise<unknown> {
    const started = Date.now();
    return new Promise((resolve, reject) => {
        const request = uni.request({
        url: url + '/api/mobile/v1/domains', method: 'GET', timeout: discoveryLimits.probeMs,
        header: { Accept: 'application/json' }, withCredentials: false,
        success: response => {
            if (import.meta.env.DEV) console.info(`[shell] 线路 ${url}: HTTP ${response.statusCode}, ${Date.now() - started}ms`);
            response.statusCode === 200 ? resolve(response.data) : reject(Object.assign(new Error('unavailable'), { status: response.statusCode }));
        },
        fail: error => {
            if (import.meta.env.DEV) console.info(`[shell] 线路 ${url}: 请求未完成, ${Date.now() - started}ms`);
            reject(error);
        },
        });
        onAbort?.(() => request.abort());
    });
}
function stopTimer() {
    clearTimeout(timer); timer = undefined;
    clearTimeout(readinessTimer); readinessTimer = undefined;
    readinessToken = '';
    clearTimeout(startupPulseTimer); startupPulseTimer = undefined;
    revealPending = false; startupPulseActive = false;
}
function failure(message: string, retainLogin = false) {
    if (disposed) return;
    finishStartup?.('error'); finishDocument?.('error'); finishContent?.('error');
    debugLog('加载失败：' + message);
    stopTimer();
    if (retainLogin) child?.setStyle({ opacity: 0 });
    else { const previous = child; child = null; previous?.close(); }
    documentDeadline = 0;
    error.value = message; state.value = 'error';
}
function contentBounds() {
    const bottom = debugEnabled.value ? debugHeight : 0;
    const height = measuredParentHeight - statusHeight - bottom;
    return { position: 'absolute', top: `${statusHeight}px`, bottom: `${bottom}px`, left: '0px', width: '100%',
        ...(height > 0 ? { height: `${height}px` } : {}) };
}
function loading() {
    // Redirects/repeated native loading events share the original display budget.
    if (state.value !== 'loading' || !documentDeadline) documentDeadline = Date.now() + webContentTimeoutMs;
    posterChannel?.dispose(); posterChannel = null; preparedDocument = -1;
    stopTimer(); state.value = 'loading'; error.value = '';
    signedIn = false; identityReady = false;
    sessionPrefix = `specpay-session-${Date.now()}-${loadSequence + 1}:`;
    sessionSequence = 0;
    debugInstalled = false; debugPrefix = ''; debugLog('网页开始加载');
    readinessToken = `specpay-ready-${Date.now()}-${++loadSequence}`;
    child?.setStyle({ opacity: 0 });
    timer = setTimeout(() => {
        const stage = !child || !pageOrigin(child.getURL()) ? '网页地址尚未就绪'
            : preparedDocument !== loadSequence ? '网页脚本尚未注入'
            : !identityReady ? '网页身份确认尚未完成' : '网页内容尚未就绪';
        if (signedIn) {
            loginRecoveryFailed.value = true;
            failure(`网页未能显示（${stage}），请重试；当前登录页面已保留。`, true);
        } else if (!retryStartupOrigin(active.value)) {
            failure(`网页未能显示（${stage}），请重试或重新连接。`);
        }
    }, Math.max(0, documentDeadline - Date.now()));
    // Some runtimes delay both rendering and loaded until subresources finish.
    // Poll the current trusted document independently of those native events.
    if (child) checkContent(child);
}
function resizeContent() {
    const view = child, revision = ++layoutRevision;
    if (!view || disposed) return;
    void nextTick(() => {
        if (disposed || child !== view || revision !== layoutRevision) return;
        let finished = false;
        const apply = (height?: number) => {
            if (finished || disposed || child !== view || revision !== layoutRevision) return;
            finished = true; clearTimeout(fallback);
            if (typeof height === 'number' && Number.isFinite(height) && height > statusHeight + (debugEnabled.value ? debugHeight : 0)) measuredParentHeight = height;
            const bounds = contentBounds();
            view.setStyle({ ...bounds, opacity: state.value === 'ready' ? 1 : 0 });
            debugLog(`窗口布局 parent=${measuredParentHeight} top=${statusHeight} height=${bounds.height ?? 'auto'} bottom=${bounds.bottom}`);
            if (revealPending) finishStartupLayout(view, bounds);
        };
        // A missing measurement callback must not strand a verified page hidden.
        // Never substitute screenHeight or a zero startup windowHeight.
        const fallback = setTimeout(() => apply(), 250);
        uni.createSelectorQuery().select('.shell').boundingClientRect(result => {
            const rect = Array.isArray(result) ? result[0] : result;
            apply(rect?.height);
        }).exec();
    });
}
function finishStartupLayout(view: Webview, bounds: ReturnType<typeof contentBounds>) {
    if (startupPulseActive || disposed || child !== view || state.value !== 'loading') return;
    const reveal = () => {
        if (disposed || child !== view || state.value !== 'loading' || !revealPending) return;
        startupPulseActive = false; startupPulseTimer = undefined; revealPending = false;
        view.setStyle({ ...contentBounds(), opacity: 0 });
        state.value = 'ready'; documentDeadline = 0;
        startupShown = true;
        hasCompletedStartup = true;
        try { uni.setStorageSync(startupCompleteKey, true); } catch { /* Optional marker. */ }
        if (loginDirectory) save({ ...loginDirectory, selected: active.value });
        finishContent?.(); finishStartup?.(); appendTimingRows();
        refreshDebug();
        // Restore current bounds, including any debug/rotation changes during the pulse.
        resizeContent();
    };
    const height = Number.parseFloat(bounds.height ?? '');
    if (!startupPulseDone && Number.isFinite(height) && height > 2) {
        startupPulseDone = true; startupPulseActive = true;
        // Keep the local welcome visible while resizing the actual H5 window.
        // Reproduce the debug panel's height change without enabling diagnostics.
        const shrink = Math.min(debugHeight, Math.floor(height / 2));
        view.setStyle({ ...bounds, height: `${height - shrink}px`,
            bottom: `${Number.parseFloat(bounds.bottom) + shrink}px`, opacity: 0 });
        startupPulseTimer = setTimeout(reveal, 120);
    } else reveal();
}
function prepareDocument(view: Webview) {
    if (!loginBootstrapReady.value || !trustedLoginPage(view)) return;
    // getURL can identify the destination before its document is ready for evalJS.
    // Retry the idempotent listener until H5 actually acknowledges bootstrap. Do
    // not restart an authenticated credential capture that is already in progress.
    if (preparedDocument !== loadSequence || (!identityReady && !signedIn)) {
        view.evalJS(loginStore!.script(active.value, sessionPrefix));
        view.evalJS(sessionSignalScript(sessionPrefix));
    }
    if (preparedDocument !== loadSequence) {
        if (import.meta.env.DEV) console.info('[shell] 已注入网页桥接：' + active.value);
        preparedDocument = loadSequence;
        posterChannel?.dispose();
        const prefix = `specpay-poster-${Date.now()}-${loadSequence}:`;
        const documentSequence = loadSequence;
        const trusted = () => !disposed && child === view && loadSequence === documentSequence && trustedLoginPage(view);
        posterChannel = posterReceiver({ prefix, trusted, evaluate: script => view.evalJS(script),
            save: data => savePoster(plus, data, trusted) });
        view.evalJS(posterBridgeScript(prefix));
    }
}
function checkContent(view: Webview) {
    if (disposed || child !== view || state.value !== 'loading') return;
    clearTimeout(readinessTimer);
    if (trustedLoginPage(view)) {
        prepareDocument(view);
        // Only a layout/readiness boolean crosses via the title event. No native
        // bridge, input values, cookies or business requests are exposed or replayed.
        view.evalJS(readinessScript(readinessToken));
    }
    readinessTimer = setTimeout(() => checkContent(view), 350);
}
function open(url: string, entryPath = config.entryPath) {
    if (import.meta.env.DEV) console.info('[shell] 打开已验证线路：' + url);
    documentDeadline = 0;
    finishDocument = beginTiming('document');
    finishContent = beginTiming('webContent');
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
    view.addEventListener('rendering', () => {
        if (disposed || child !== view || state.value !== 'loading') return;
        installDebug();
        // Do not wait for slow images/subresources to complete window.onload.
        checkContent(view);
    });
    view.addEventListener('loaded', () => {
        if (disposed || child !== view || state.value === 'error') return;
        // An empty native window can emit loaded before the requested document.
        if (!pageOrigin(view.getURL())) return;
        if (debugEnabled.value) debugUrl.value = safeAddress(view.getURL());
        finishDocument?.(); appendTimingRows();
        debugLog('文档 loaded，等待页面内容'); installDebug();
        checkContent(view);
    });
    view.addEventListener('titleUpdate', event => {
        if (!event || typeof event.title !== 'string') return;
        if (!disposed && child === view && event.title?.startsWith('specpay-poster-')) {
            void posterChannel?.receive(event.title); return;
        }
        if (!disposed && child === view && sessionPrefix && event.title?.startsWith(sessionPrefix) && trustedLoginPage(view)) {
            try {
                const value = JSON.parse(event.title.slice(sessionPrefix.length));
                if (value.tenantId !== loginDirectory?.tenantId || typeof value.signedIn !== 'boolean'
                    || !Number.isSafeInteger(value.sequence) || value.sequence <= sessionSequence) return;
                sessionSequence = value.sequence;
                if (value.pending === true) {
                    signedIn = false; expectsSignedIn = true; identityReady = false; loginStore?.pending();
                    return;
                }
                signedIn = value.signedIn;
                credentialRevision = value.credentialRevision ?? 0;
                if (signedIn) { expectsSignedIn = true; void confirmPageIdentity(view, sessionSequence); }
                else if (value.signedOut === true) { loginStore?.clear(); expectsSignedIn = false; identityReady = true; }
                else if (value.expired === true) { loginStore?.clear(); expectsSignedIn = false; identityReady = true; }
                else if (!expectsSignedIn) identityReady = true;
                else { loginRecoveryFailed.value = true; failure('登录状态尚未恢复，请重试；登录凭证已保留。'); }
            } catch (error) { loginStorageFailure(error); }
            return;
        }
        if (!disposed && child === view && debugEnabled.value && debugPrefix && event.title?.startsWith(debugPrefix)) {
            debugMessages(event.title.slice(debugPrefix.length)).forEach(debugLog); return;
        }
        if (!disposed && child === view && state.value === 'loading' && readinessToken
            && event.title === readinessToken + '-resource-error' && trustedLoginPage(view)) {
            if (!signedIn && retryStartupOrigin(active.value)) return;
            loginRecoveryFailed.value = signedIn;
            failure('网页必要资源加载失败，请重试或重新连接。', signedIn);
            return;
        }
        if (disposed || child !== view || state.value !== 'loading'
            || !identityReady || !readinessToken || event.title !== readinessToken
            || !pageOrigin(view.getURL())) return;
        stopTimer(); revealPending = true;
        // Once content is ready, pulse its native bounds behind the welcome page.
        // Only reveal after restoring; stale windows cannot complete this sequence.
        resizeContent();
    });
    view.addEventListener('error', () => {
        if (child !== view) return;
        if (!signedIn && retryStartupOrigin(active.value)) return;
        failure('网页暂时无法打开，请稍后重试。', signedIn);
    });
    page.$getAppWebview().append(view);
    loading(); view.loadURL(url + '/?app_webview=1&native_auth=token-v1' + entryPath.slice(1));
    // #endif
    // #ifndef APP-PLUS
    failure('请使用 HBuilderX 运行到手机或云打包，此工程是 App 网页容器。');
    // #endif
}
async function connect() {
    if (detecting || disposed) return;
    clearTimeout(startupRetryTimer); pendingStartupRetry = false; connectionUnavailable = false;
    finishStartup = beginTiming('startup');
    detecting = true; state.value = 'discovering'; error.value = ''; loginRecoveryFailed.value = false; stopTimer();
    try { await captureLogin(); }
    catch {
        loginRecoveryFailed.value = true;
        failure('登录凭证尚未保存，请重试；当前登录页面已保留。', true);
        detecting = false;
        return;
    }
    const previous = cache();
    const previousChild = child; child = null; previousChild?.close();
    loginBootstrapReady.value = false;
    let restoringOrigin = '';
    try {
        const discoveryTiming = beginTiming('lineDiscovery');
        const result = await networkRecovery.run(() => directoryRefresh && !failedStartupOrigins.size ? directoryRefresh : discover(config.seeds, config.tenantSlug, previous, probe,
            Date.now, { ...discoveryLimits, excludedOrigins: [...failedStartupOrigins],
                // A host that actually rendered H5 has useful HTTP cache. Keep it
                // when healthy; a slightly faster cold host can cost far more to load.
                preferredGraceMs: hasCompletedStartup ? discoveryLimits.probeMs : discoveryLimits.preferredGraceMs,
            })).then(value => { discoveryTiming(); return value; }, error => { discoveryTiming('error'); throw error; });
        if (disposed) return;
        state.value = 'restoring';
        await configureLogin(result, previous);
        expectsSignedIn = loginStore!.expectedSignedIn?.() ?? false;
        debugLog('正在验证目标线路身份');
        restoringOrigin = result.selected;
        // A directory response does not prove that this host's H5 can render.
        // Persist the new selection only when its page is actually ready.
        save({ ...result, selected: previous?.selected ?? '' }); lastRefresh = Date.now(); active.value = result.selected;
        stopDebug();
        await startSessionDocument({
            expectedSignedIn: expectsSignedIn, guestEntry: config.entryPath,
            preload: entry => open(result.selected, entry),
            restore: async () => {
                const timing = beginTiming('loginRestore');
                try { const identity = await loginStore!.restore(result.selected); timing(); return identity; }
                catch (error) { timing('error'); throw error; }
            },
            current: () => !disposed && !pendingStartupRetry && state.value !== 'error' && !!child,
            commit: (identity, redirect) => {
                expectsSignedIn = identity.signedIn;
                if (redirect) open(result.selected, redirect);
                loginBootstrapReady.value = true;
                debugLog('目标线路身份验证通过');
                if (child) checkContent(child);
            },
        });
    } catch (e) {
        if (disposed || e instanceof StartupCancelled) return;
        const diagnostic = (e as { diagnostic?: string })?.diagnostic;
        if ((diagnostic === 'network' || diagnostic === 'timeout') && retryStartupOrigin(restoringOrigin)) return;
        connectionUnavailable = !!(e as { networkUnavailable?: boolean })?.networkUnavailable || diagnostic === 'network' || diagnostic === 'timeout';
        loginRecoveryFailed.value = !connectionUnavailable && e instanceof LoginRestoreUnavailable;
        const storageDiagnostic = loginDiagnostic('storage', e);
        const storageFailure = storageDiagnostic.startsWith('IOS-') || storageDiagnostic.startsWith('SAVE-META-')
            || ['SAVE-RECOVERY-PENDING', 'SAVE-CREDENTIAL-MISSING'].includes(storageDiagnostic);
        if (storageFailure) loginRecoveryFailed.value = true;
        failure(storageFailure ? `本机登录状态恢复未完成（${storageDiagnostic}），请重试；已保存凭证将保留。` : connectionUnavailable
            ? '连接暂未完成，请稍后重试。首次使用请允许应用联网；已保存的登录状态不会被清除。'
            : e instanceof Error ? e.message : '连接失败，请重试。');
    }
    finally {
        detecting = false;
        if (pendingStartupRetry && !disposed) startupRetryTimer = setTimeout(() => { void connect(); }, 0);
    }
}
async function retry() {
    // Keep the same document/mailbox while native capture is recovering. Opening
    // another document would discard the only current account credential.
    const view = child;
    if (view && loginRecoveryFailed.value && signedIn && trustedLoginPage(view)) {
        if (detecting) return;
        detecting = true; state.value = 'loading'; error.value = '';
        documentDeadline = Date.now() + webContentTimeoutMs;
        readinessToken = `specpay-ready-${Date.now()}-${loadSequence}`;
        timer = setTimeout(() => failure('网页内容尚未就绪，请重试。', true), webContentTimeoutMs);
        try { await confirmPageIdentity(view, sessionSequence); }
        finally { detecting = false; }
        return;
    }
    failedStartupOrigins.clear();
    return connect();
}
function signInAgain() {
    if (detecting || !loginRecoveryFailed.value) return;
    uni.showModal({ title: '重新登录', content: '将清除本机保存的登录状态，需要重新输入账号和密码。',
        confirmText: '继续', cancelText: '取消', success: result => {
            if (!result.confirm) return;
            try { loginStore?.clear(); signedIn = false; void connect(); }
            catch (error) { loginStorageFailure(error); }
        } });
}
function changeLine() {
    if (detecting) return;
    uni.showModal({ title: '重新连接', content: '将重新选择线路并恢复当前登录。请确认没有正在提交的操作。',
        confirmText: '继续', cancelText: '取消', success: result => { if (result.confirm) void connect(); } });
}
function back() {
    if (!child) return;
    const view = child;
    view.canBack(result => {
        if (disposed || child !== view) return;
        if (result.canBack) view.back();
        else uni.showModal({ title: '退出应用', content: '确定退出吗？', confirmText: '退出', cancelText: '取消', success: r => {
            // #ifdef APP-PLUS
            if (r.confirm) plus.runtime.quit();
            // #endif
        } });
    });
}
function networkChanged(value: { isConnected: boolean }) {
    if (disposed || !value.isConnected) return;
    networkRecovery.wake();
    if (!startupShown && connectionUnavailable && state.value === 'error' && !child && !detecting) {
        failedStartupOrigins.clear(); void connect();
    }
}
onReady(() => { uni.onWindowResize(resizeContent); uni.onNetworkStatusChange(networkChanged); void connect(); });
onShow(() => {
    background = false; networkRecovery.resume();
    if (state.value === 'ready') refreshDebug();
    if (!startupShown && connectionUnavailable && state.value === 'error' && !child && !detecting) {
        failedStartupOrigins.clear(); void connect();
    }
    resizeContent();
    // Refresh the directory cache without moving an active login or replaying an operation.
    if (!child || detecting || directoryRefresh || Date.now() - lastRefresh < 60000) return;
    lastRefresh = Date.now();
    directoryRefresh = discover(config.seeds, config.tenantSlug, cache(), probe);
    void directoryRefresh.then(value => {
        if (!disposed) save({ ...value, selected: state.value === 'ready' ? active.value : cache()?.selected ?? '' });
    }).catch(() => { /* Retain the last verified directory during a temporary outage. */ })
        .finally(() => { directoryRefresh = null; });
});
onHide(() => { void captureLogin().catch(error => loginStorageFailure(error, loginStore?.captureStage())); background = true; networkRecovery.pause(); stopDebug(); });
onBackPress(() => { back(); return true; });
onUnload(() => { disposed = true; networkRecovery.dispose(); clearTimeout(startupRetryTimer); posterChannel?.dispose(); stopDebug(); uni.offWindowResize(resizeContent); uni.offNetworkStatusChange(networkChanged); stopTimer(); child?.close(); child = null; });
</script>
<template>
    <view class="shell" :style="{ paddingTop: statusHeight + 'px', paddingBottom: debugEnabled ? debugHeight + 'px' : '0px' }">
        <view v-if="debugEnabled" class="debug-panel" :style="{ height: debugHeight + 'px' }">
            <text class="debug-title">App 调试 · {{ manifest.versionName }} · {{ state }}</text>
            <text class="debug-address">{{ debugUrl }}</text>
            <scroll-view scroll-y class="debug-log"><text v-for="(row, index) in debugRows" :key="index" class="debug-row">{{ row }}</text></scroll-view>
        </view>
        <view v-if="state !== 'ready'" class="welcome">
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
                    <button v-if="loginRecoveryFailed" class="secondary" @click="signInAgain">重新登录</button>
                    <button v-else class="secondary" @click="changeLine">重新连接</button>
                </view>
                <view v-else class="loading-state" role="status">
                    <view class="loading-track"><view class="loading-glow"/></view>
                    <text class="loading-label">{{ waitingForNetwork ? '正在等待网络连接，首次使用请允许应用联网' : state === 'discovering' ? '正在连接服务' : !loginBootstrapReady ? '正在恢复登录并加载页面' : '正在为您开启全球支付' }}</text>
                </view>
            </view>
            <text class="security-note">保护账户安全，请勿向任何人透露密码或验证码。</text>
        </view>
    </view>
</template>
<style scoped>
.debug-panel { position: fixed; z-index: 100; bottom: 0; left: 0; right: 0; padding: 10px 12px calc(10px + env(safe-area-inset-bottom)); background: #171717; border-top: 1px solid #d6bb74; color: #eee; display: flex; flex-direction: column; }
.debug-title { font-size: 13px; color: #e2c984; }.debug-address { font-size: 11px; overflow-wrap: anywhere; margin: 6px 0; }.debug-log { flex: 1; min-height: 0; }.debug-row { display: block; font: 11px/1.6 monospace; overflow-wrap: anywhere; }
.shell { position: fixed; top: 0; right: 0; bottom: 0; left: 0; overflow: hidden; box-sizing: border-box; background: #11100c; color: #f6edce; }
.welcome { height: 100%; overflow-y: auto; box-sizing: border-box; display: flex; flex-direction: column; background: radial-gradient(ellipse at 95% 35%, #342915 0%, #17150f 43%, #11100c 75%); padding: 30px 24px 24px; }
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
