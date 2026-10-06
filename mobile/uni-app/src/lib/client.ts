import { ref, onScopeDispose } from 'vue';
import { ApiError, native, request, setCsrf, setToken, upload, type Upload, type UploadProgress } from './api';
import { bootstrap, session } from './session';
import { t, errorMessage } from './i18n';
import { go } from './navigation';
export type ClientPage<T = Record<string, unknown>> = { component: string; props: T };
export type ActionResult = {
    redirect?: string;
    success?: string;
    csrfToken?: string;
    token?: string;
};
export async function getPage<T>(path: string) {
    return request<ClientPage<T>>('/client' + path);
}
export function requestId(): string {
    // An idempotency identifier, never an authentication token. Keep it unchanged on retry.
    if (globalThis.crypto?.randomUUID) return globalThis.crypto.randomUUID();
    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
        const r = Math.floor(Math.random() * 16);
        return (c === 'x' ? r : (r & 3) | 8).toString(16);
    });
}
export function useAction() {
    let disposed = false;
    onScopeDispose(() => { disposed = true; });
    const pending = ref(false);
    const errors = ref<Record<string, string>>({});
    const failureCode = ref<string | null>(null);
    const failureStatus = ref<number | null>(null);
    async function submit(
        path: string,
        data: Record<string, unknown>,
        options: {
            files?: Upload[];
            onProgress?: (progress: UploadProgress) => void;
            navigate?: boolean;
            success?: () => void | Promise<void>;
        } = {},
    ) {
        if (pending.value || disposed) return;
        pending.value = true;
        errors.value = {};
        failureStatus.value = null;
        failureCode.value = null;
        try {
            const response = options.files?.length
                ? await upload<ActionResult>(
                      '/client' + path,
                      data,
                      options.files,
                      (progress) => { if (!disposed) options.onProgress?.(progress); },
                  )
                : await request<ActionResult>('/client' + path, 'POST', data);
            if (disposed) return;
            if (response?.csrfToken) setCsrf(response.csrfToken);
            if (response?.token && native) {
                setToken(response.token);
                await bootstrap();
            }
            if (
                (!native && /^\/register\/challenges\/[a-f0-9-]+\/complete$/.test(path)) ||
                path.startsWith('/account/information/')
            )
                await bootstrap();
            if (response?.success)
                uni.showToast({ title: errorMessage(response.success), icon: 'none' });
            await options.success?.();
            if (options.navigate !== false && response?.redirect) go(response.redirect, true);
            return response;
        } catch (error) {
            if (disposed) return;
            failureCode.value = error instanceof ApiError ? error.payload?.error?.code ?? null : null;
            failureStatus.value = error instanceof ApiError ? error.status : 0;
            errors.value = explainError(error);
        } finally {
            pending.value = false;
        }
    }
    return { pending, errors, failureStatus, failureCode, submit };
}
export function explainError(error: unknown): Record<string, string> {
    if (!(error instanceof ApiError)) return { form: t('Unable to load. Please try again.') };
    if (error.status === 401) return { form: t('Please sign in to continue.') };
    if (error.status === 419)
        return { form: t('Your session has expired. Please refresh and try again.') };
    if (error.status === 429) return { form: t('Too many attempts. Please try again later.') };
    if (error.payload?.errors)
        return Object.fromEntries(
            Object.entries(error.payload.errors).map(([k, v]) => [
                k,
                errorMessage(Array.isArray(v) ? v[0] : String(v)),
            ]),
        );
    return {
        form: error.payload?.error?.message
            ? errorMessage(error.payload.error.message)
            : t('Unable to load. Please try again.'),
    };
}
export async function ensureBootstrap() {
    if (!session.value) await bootstrap();
}
