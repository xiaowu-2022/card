import { ref } from 'vue';
import company from '../generated/company.json';
import { companyOrigin, ensureCompanyOrigin } from './origin';

declare const plus: { runtime: { openURL: (url: string, fail: () => void) => void } };

const native = import.meta.env.UNI_PLATFORM === 'app';
export const appUpdate = ref<{ status: 'ready' | 'checking' | 'required' | 'error'; version: string; url: string }>({
    status: native ? 'checking' : 'ready', version: '', url: '',
});
let pending: Promise<void> | undefined;
let checkedAt = 0;

export function checkAppUpdate(force = false): Promise<void> {
    if (!native) return Promise.resolve();
    if (pending) return pending;
    if (!force && appUpdate.value.status === 'ready' && Date.now() - checkedAt < 60000) return Promise.resolve();
    appUpdate.value = { ...appUpdate.value, status: 'checking' };
    pending = (async () => {
        try {
            await ensureCompanyOrigin();
            const origin = companyOrigin();
            const data = await new Promise<any>((resolve, reject) => uni.request({
                url: origin + '/api/mobile/v1/app-release', method: 'GET',
                header: { Accept: 'application/json' }, withCredentials: false, timeout: 10000,
                success: response => response.statusCode === 200 ? resolve(response.data) : reject(new Error('Release unavailable')),
                fail: reject,
            }));
            const installed = uni.getAppBaseInfo();
            const code = Number(installed.appVersionCode);
            if (data?.tenantSlug !== company.tenantSlug || data?.appId !== installed.appId
                || !Number.isSafeInteger(data.versionCode) || data.versionCode < 1
                || !Number.isSafeInteger(code) || code < 1
                || typeof data.versionName !== 'string'
                || typeof data.path !== 'string' || !/^\/app-releases\/[a-f0-9-]+\/[a-f0-9]{64}\.apk$/.test(data.path)) {
                throw new Error('Invalid release');
            }
            checkedAt = Date.now();
            appUpdate.value = { status: code < data.versionCode ? 'required' : 'ready', version: data.versionName, url: origin + data.path };
        } catch {
            appUpdate.value = { ...appUpdate.value, status: 'error' };
        } finally {
            pending = undefined;
        }
    })();
    return pending;
}

export async function ensureLatestApp() {
    await checkAppUpdate();
    if (appUpdate.value.status !== 'ready') throw new Error('App update required');
}

export function downloadAppUpdate() {
    // #ifdef APP-PLUS
    if (appUpdate.value.status !== 'required') return;
    const runtime = typeof plus === 'undefined' ? undefined : plus.runtime;
    if (!runtime) { appUpdate.value.status = 'error'; return; }
    runtime.openURL(appUpdate.value.url, () => { appUpdate.value.status = 'error'; });
    // #endif
}
