import { reactive, ref } from 'vue';
import { ApiError, company, native, request, sessionGeneration, setCsrf, setToken } from './api';
import { configureLocale } from './i18n';
export type Bootstrap = {
    tenant: { id: string; slug: string; name: string; logoUrl: string | null; primaryColor: string };
    user: { id: string; accountId: string; displayName: string | null; email: string | null } | null;
    locale: string; locales: string[]; timezone: string; csrfToken: string | null; restricted: boolean;
    unread: { messages: number; support: number };
};
export const session = ref<Bootstrap | null>(null);
export const unread = reactive({ messages: 0, support: 0 });
let pending: Promise<void> | null = null;
export async function bootstrap() {
    if (pending) return pending;
    let generation = sessionGeneration;
    pending = (async () => {
        let data: Bootstrap;
        try { data = await request<Bootstrap>('/bootstrap'); }
        catch (error) {
            if (error instanceof ApiError && error.status === 401 && generation === sessionGeneration) {
                clearSession();
                generation = sessionGeneration;
                data = await request<Bootstrap>('/bootstrap');
            } else throw error;
        }
        if (generation !== sessionGeneration) return;
        if (data.tenant.slug !== company.tenantSlug) { clearSession(); throw new ApiError(403); }
        session.value = data;
        Object.assign(unread, data.unread);
        setCsrf(data.csrfToken);
        configureLocale(data.locale, data.timezone);
    })().finally(() => { pending = null; });
    return pending;
}
export function clearSession() { setToken(null); session.value = null; Object.assign(unread, { messages: 0, support: 0 }); }
export async function login(identifier: string, password: string) {
    if (pending) await pending;
    const result = await request<{ token?: string; csrfToken?: string }>('/login', 'POST', { identifier, password, device_name: 'uni-app' });
    if (native) { if (!result.token) throw new ApiError(401); setToken(result.token); }
    if (result.csrfToken) setCsrf(result.csrfToken);
    session.value = null;
    await bootstrap();
}
export async function logout() {
    await request('/logout', 'POST');
    clearSession();
    uni.reLaunch({ url: '/pages/login/index' });
}
export async function refreshUnread() {
    if (!session.value?.user) return;
    const generation = sessionGeneration;
    try {
        const counts = await request<{ messages: number; support: number }>('/unread');
        if (generation === sessionGeneration && session.value?.user) Object.assign(unread, counts);
    } catch (error) {
        if (error instanceof ApiError && error.status === 401 && generation === sessionGeneration) {
            clearSession(); uni.reLaunch({ url: '/pages/login/index' });
        }
        // Keep the last successful count when offline; never display a false zero.
    }
}
export async function requireUser() {
    if (!session.value) await bootstrap();
    if (!session.value?.user) { uni.reLaunch({ url: '/pages/login/index' }); return false; }
    return true;
}
