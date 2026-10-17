import { beginTiming } from './startup-timing';
import { computed, reactive, ref } from 'vue';
import { remindSupportUnread, resetSupportReminder } from './support-reminder';
import {
    ApiError,
    company,
    native,
    request,
    sessionGeneration,
    setCsrf,
    setToken,
    clearFlow,
} from './api';
import { setPublicAssets } from './origin';
import { configureLocale } from './i18n';
import { clearCardsPreview } from './cards-preview';
import { notifyWebviewSession } from './webview-session';
export type Bootstrap = {
    supportAgent?: boolean;
    publicAssets?: Record<string, string>;
    appDownloads?: import('./app-download').AppDownloads;
    tenant: {
        id: string;
        slug: string;
        name: string;
        logoUrl: string | null;
        logoSources?: string[];
        primaryColor: string;
    };
    user: {
        id: string;
        accountId: string;
        displayName: string | null;
        email: string | null;
    } | null;
    locale: string;
    locales: string[];
    timezone: string;
    csrfToken: string | null;
    restricted: boolean;
    unread: { messages: number; support: number; agentSupport?: number } | null;
};
export const session = ref<Bootstrap | null>(null);
export const isSupportAgent = computed(() => !!session.value?.user && !session.value.restricted && session.value.supportAgent === true);
export const unread = reactive({ messages: 0, support: 0, agentSupport: 0 });
let pending: Promise<void> | null = null;
let walletEpoch = 0, walletReadyFor: string | null = null;
let walletPending: { key: string; promise: Promise<void> } | null = null;
function resetWalletReadiness() {
    clearCardsPreview();
    walletEpoch++;
    walletReadyFor = null;
    walletPending = null;
}
export function consumerSessionScope(): string | null {
    const value = session.value;
    return value?.user ? JSON.stringify([walletEpoch, sessionGeneration, value.tenant.id, value.user.id]) : null;
}
export async function bootstrap() {
    if (pending) return pending;
    const finishTiming = beginTiming('bootstrap');
    let outcome: 'ok' | 'error' = 'ok';
    let generation = sessionGeneration;
    pending = (async () => {
        const data = await request<Bootstrap>('/bootstrap?defer_unread=1');
        if (generation !== sessionGeneration) return;
        if (native && data.tenant.slug !== company.tenantSlug) {
            clearSession();
            throw new ApiError(403);
        }
        setPublicAssets(data.publicAssets);
        session.value = data;
        if (data.unread) {
            Object.assign(unread, data.unread, { agentSupport: data.unread.agentSupport ?? 0 });
            remindSupportUnread(data.unread.agentSupport ?? 0, isSupportAgent.value);
        }
        setCsrf(data.csrfToken);
        configureLocale(data.locale, data.timezone);
        notifyWebviewSession(!!data.user, data.tenant.id);
    })().catch(error => { outcome = 'error'; throw error; }).finally(() => {
        finishTiming(outcome);
        pending = null;
    });
    return pending;
}
export function clearSession(explicitLogout = false, confirmedExpired = false) {
    notifyWebviewSession(false, session.value?.tenant.id, explicitLogout, confirmedExpired);
    resetWalletReadiness();
    resetSupportReminder();
    setToken(null);
    clearFlow();
    session.value = null;
    Object.assign(unread, { messages: 0, support: 0, agentSupport: 0 });
}
export async function login(identifier: string, password: string) {
    if (pending) await pending;
    const result = await request<{ token?: string; csrfToken?: string }>('/login', 'POST', {
        identifier,
        password,
        device_name: 'uni-app',
    });
    if (native) {
        if (!result.token) throw new ApiError(401);
        setToken(result.token);
    }
    if (result.csrfToken) setCsrf(result.csrfToken);
    resetWalletReadiness();
    resetSupportReminder();
    session.value = null;
    await bootstrap();
}
export async function logout() {
    await request('/logout', 'POST');
    clearSession(true);
    uni.reLaunch({ url: '/pages/login/index' });
}
let lastPresence = 0;
export async function refreshUnread() {
    if (!session.value?.user) return;
    const generation = sessionGeneration;
    // Send foreground presence separately; reading counters remains read-only.
    let foreground = true;
    // #ifdef H5
    foreground = document.visibilityState === 'visible';
    // #endif
    if (foreground && Date.now() - lastPresence > 20000) {
        lastPresence = Date.now();
        void request('/presence', 'POST', {}).catch(() => {});
    }
    try {
        const counts = await request<{ messages: number; support: number; agentSupport?: number; supportAgent?: boolean }>(
            '/unread',
        );
        if (generation === sessionGeneration && session.value?.user) {
            session.value.supportAgent = counts.supportAgent === true;
            Object.assign(unread, counts, { agentSupport: counts.agentSupport ?? 0 });
            remindSupportUnread(counts.agentSupport ?? 0, isSupportAgent.value);
        }
    } catch (error) {
        resetSupportReminder();
        if (error instanceof ApiError && error.status === 401 && generation === sessionGeneration) {
            try {
                await bootstrap();
                if (!session.value?.user && generation === sessionGeneration) {
                    clearSession();
                    uni.reLaunch({ url: '/pages/login/index' });
                }
            } catch { /* Keep credentials and the last session on transient recovery errors. */ }
        }
        // Keep the last successful count when offline; never display a false zero.
    }
}
export async function requireUser(options: { ensureWallet?: boolean } = {}) {
    if (!session.value) await bootstrap();
    if (!session.value?.user) {
        uni.reLaunch({ url: '/pages/login/index' });
        return false;
    }
    // Profile/menu reads do not depend on wallet provisioning. Financial pages keep the default gate.
    if (options.ensureWallet === false) return true;
    // Provisioning already succeeded for this signed-in user. Page reads still
    // revalidate authorization on the server; this never caches wallet balances.
    const key = consumerSessionScope()!;
    if (walletReadyFor !== key) {
        if (walletPending?.key !== key) {
            const flight = { key, promise: Promise.resolve() };
            walletPending = flight;
            flight.promise = request('/wallet/ensure', 'POST', {}).then(() => {
                if (walletPending === flight && consumerSessionScope() === key) walletReadyFor = key;
            }).finally(() => {
                if (walletPending === flight) walletPending = null;
            });
        }
        await walletPending.promise;
    }
    return consumerSessionScope() === key;
}

let sessionConfirmation: Promise<boolean> | null = null;
/** Recheck in the background without reloading a page, replacing balances or clearing on network failure. */
export function confirmSession(): Promise<boolean> {
    if (sessionConfirmation) return sessionConfirmation;
    if (!session.value?.user) return Promise.resolve(true);
    const scope = consumerSessionScope();
    const finishTiming = beginTiming('resumeCheck');
    let outcome: 'ok' | 'error' = 'ok';
    sessionConfirmation = (async () => {
        const value = await request<{ tenant: { id: string }; user: { id: string } | null; restricted: boolean }>('/session');
        if (scope !== consumerSessionScope()) return false;
        if (value.tenant?.id !== session.value?.tenant.id || typeof value.restricted !== 'boolean'
            || (value.user !== null && (typeof value.user?.id !== 'string' || !value.user.id))) throw new ApiError(503);
        if (!value.user) {
            clearSession(false, true);
            uni.reLaunch({ url: '/pages/login/index' });
            return false;
        }
        if (value.user.id !== session.value?.user?.id) {
            resetWalletReadiness();
            resetSupportReminder();
            await bootstrap();
            uni.reLaunch({ url: !session.value?.user ? '/pages/login/index' : session.value.restricted ? '/pages/screen/index?path=%2Faccount%2Frestricted' : '/pages/assets/index' });
            return false;
        }
        session.value!.restricted = value.restricted;
        if (value.restricted) {
            uni.reLaunch({ url: '/pages/screen/index?path=%2Faccount%2Frestricted' });
            return false;
        }
        return true;
    })().catch(error => {
        outcome = 'error';
        if (native && error instanceof ApiError && error.status === 401 && scope === consumerSessionScope()) {
            clearSession(false, true);
            uni.reLaunch({ url: '/pages/login/index' });
            return false;
        }
        throw error;
    }).finally(() => {
        finishTiming(outcome);
        sessionConfirmation = null;
    });
    return sessionConfirmation;
}
