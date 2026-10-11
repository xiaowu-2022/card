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
    const registrationCreated = ref(false);
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
            explainFailure?: (error: unknown) => Record<string, string>;
            success?: () => void | Promise<void>;
        } = {},
    ) {
        if (pending.value || disposed || registrationCreated.value) return;
        pending.value = true;
        errors.value = {};
        failureStatus.value = null;
        failureCode.value = null;
        const registration = /^\/register\/challenges\/[a-f0-9-]+\/complete$/.test(path);
        let registered = false;
        try {
            const response = options.files?.length
                ? await upload<ActionResult>(
                      '/client' + path,
                      data,
                      options.files,
                      (progress) => { if (!disposed) options.onProgress?.(progress); },
                  )
                : await request<ActionResult>('/client' + path, 'POST', data);
            if (registration && response?.redirect !== '/dashboard')
                throw new ApiError(502, { error: { code: 'CLIENT_RESPONSE_INVALID' } });
            registered = registration;
            registrationCreated.value = registration;
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
            errors.value = options.explainFailure ? options.explainFailure(error) : explainError(error, true);
            if (registered) errors.value = { form: t('Your account was created, but sign-in setup failed. Go to sign in; do not create the account again.') + '\n' + Object.values(errors.value).join('\n') };
            else if (registration) errors.value = { ...errors.value, form: t('Account creation was not confirmed.') + (errors.value.form ? '\n' + errors.value.form : '') };
        } finally {
            pending.value = false;
        }
    }
    return { pending, registrationCreated, errors, failureStatus, failureCode, submit };
}
export function explainError(error: unknown, diagnosticsEnabled = false): Record<string, string> {
    if (!(error instanceof ApiError)) return { form: t('The app could not finish processing the response. Refresh or sign in again. [CLIENT_RESPONSE_ERROR]') };
    const code = error.payload?.error?.code;
    const reasons: Record<string, string> = {
        CLIENT_REQUEST_TIMEOUT: 'The request timed out. Its result is unknown; check your account status before retrying.',
        CLIENT_NETWORK_UNAVAILABLE: 'Cannot connect to the server. Check your network connection.',
        CLIENT_ORIGIN_UNAVAILABLE: 'Cannot resolve the company service address. Try again later.',
    };
    const fallback = error.status === 0 ? 'Cannot connect to the server. Check your network connection.'
        : error.status === 401 ? 'Please sign in to continue.'
        : error.status === 419 ? 'Your session has expired. Please refresh and try again.'
        : error.status === 429 ? 'Too many attempts. Please try again later.'
        : error.status === 403 ? 'This operation is not permitted for your account or company.'
        : error.status === 404 ? 'The requested service or record was not found. Refresh and try again.'
        : [502, 503, 504].includes(error.status) ? 'The server is temporarily unavailable. Try again later.'
        : error.status >= 500 ? 'The server could not process this request. Contact support with the reference below.'
        : error.status === 422 ? 'Some submitted information is invalid. Check the fields and try again.'
        : 'The server returned an unexpected response. Contact support with the reference below.';
    const result = error.payload?.errors && typeof error.payload.errors === 'object'
        ? Object.fromEntries(Object.entries(error.payload.errors).map(([k, v]) => [k, errorMessage(Array.isArray(v) ? v[0] : String(v))]))
        : { form: code && reasons[code] ? t(reasons[code])
            : error.status < 500 && error.payload?.error?.message ? errorMessage(error.payload.error.message, fallback) : t(fallback) };
    const reference = error.payload?.error?.request_id ?? error.requestId;
    const diagnostics = [error.status ? `HTTP ${error.status}` : '',
        code && /^[A-Z][A-Z0-9_]{0,79}$/.test(code) ? code : '',
        reference && /^[a-f0-9-]{36}$/i.test(reference) ? t('Request reference: {{reference}}', { reference }) : '',
    ].filter(Boolean).join(' · ');
    if (diagnosticsEnabled && code !== 'USER_OPERATION_RESTRICTED' && diagnostics) result.form = [result.form, diagnostics].filter(Boolean).join('\n');
    return result;
}
export async function ensureBootstrap() {
    if (!session.value) await bootstrap();
}
