import { reactive, ref } from 'vue';
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
export type Bootstrap = {
    publicAssets?: Record<string, string>;
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
    unread: { messages: number; support: number; agentSupport?: number };
};
export const session = ref<Bootstrap | null>(null);
export const unread = reactive({ messages: 0, support: 0, agentSupport: 0 });
let pending: Promise<void> | null = null;
export async function bootstrap() {
    if (pending) return pending;
    let generation = sessionGeneration;
    pending = (async () => {
        let data: Bootstrap;
        try {
            data = await request<Bootstrap>('/bootstrap');
        } catch (error) {
            if (
                error instanceof ApiError &&
                error.status === 401 &&
                generation === sessionGeneration
            ) {
                clearSession();
                generation = sessionGeneration;
                data = await request<Bootstrap>('/bootstrap');
            } else throw error;
        }
        if (generation !== sessionGeneration) return;
        if (data.tenant.slug !== company.tenantSlug) {
            clearSession();
            throw new ApiError(403);
        }
        setPublicAssets(data.publicAssets);
        session.value = data;
        Object.assign(unread, data.unread, { agentSupport: data.unread.agentSupport ?? 0 });
        remindSupportUnread(data.unread.agentSupport ?? 0);
        setCsrf(data.csrfToken);
        configureLocale(data.locale, data.timezone);
    })().finally(() => {
        pending = null;
    });
    return pending;
}
export function clearSession() {
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
    session.value = null;
    await bootstrap();
}
export async function logout() {
    await request('/logout', 'POST');
    clearSession();
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
        const counts = await request<{ messages: number; support: number; agentSupport?: number }>(
            '/unread',
        );
        if (generation === sessionGeneration && session.value?.user) {
            Object.assign(unread, counts, { agentSupport: counts.agentSupport ?? 0 });
            remindSupportUnread(counts.agentSupport ?? 0);
        }
    } catch (error) {
        if (error instanceof ApiError && error.status === 401 && generation === sessionGeneration) {
            clearSession();
            uni.reLaunch({ url: '/pages/login/index' });
        }
        // Keep the last successful count when offline; never display a false zero.
    }
}
export async function requireUser() {
    if (!session.value) await bootstrap();
    if (!session.value?.user) {
        uni.reLaunch({ url: '/pages/login/index' });
        return false;
    }
    await request('/wallet/ensure', 'POST', {});
    return true;
}
