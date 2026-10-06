import { ensureLatestApp } from './app-update';
import company from '../generated/company.json';
import { companyOrigin, ensureCompanyOrigin } from './origin';
import { androidCredentialVault, type AndroidBridge } from './device-credentials';
export const native = import.meta.env.UNI_PLATFORM === 'app';
let token: string | null = null;
let tokenLoaded = false;
function credentialVault() {
    const runtime = (globalThis as typeof globalThis & { plus?: { os: { name: string }; android: AndroidBridge } }).plus;
    if (!runtime) throw new Error('Secure credential storage unavailable');
    // iOS retains the existing memory-only policy until a Keychain bridge exists.
    if (runtime.os.name !== 'Android') return null;
    const scope = `${company.appId}:${company.tenantSlug}:${company.apiOrigin}`;
    const key = `encrypted-session:${scope}`;
    return androidCredentialVault(runtime.android, scope, {
        get: () => uni.getStorageSync(key),
        set: value => uni.setStorageSync(key, value),
        remove: () => uni.removeStorageSync(key),
    });
}
let csrf: string | null = null;
let csrfUpdatedAt = 0;
let flow: string | null = null;
let language = '';
let page = '/';
export let sessionGeneration = 0;
export function setToken(value: string | null) {
    if (native) credentialVault()?.write(value);
    token = value;
    tokenLoaded = true;
    sessionGeneration++;
}
export function setCsrf(value: string | null) {
    csrf = value;
    csrfUpdatedAt = Date.now();
}
export function setLanguage(value: string) {
    language = value;
}
export function setCurrentPage(value: string) {
    page = value;
}
export function clearFlow() {
    flow = null;
}
export class ApiError extends Error {
    constructor(
        public status: number,
        public payload?: {
            error?: { code?: string; message?: string };
            errors?: Record<string, string[]>;
        },
    ) {
        super('Request failed');
    }
}
function url(path: string) {
    if (!/^\/[a-z0-9/?=&_%+.,:-]+$/i.test(path) || path.startsWith('//') || path.includes('..'))
        throw new Error('Invalid API path');
    return `${native ? companyOrigin() : ''}${native ? '/api/mobile/v1' : '/api/v1'}${path}`;
}
function headers() {
    if (native && !tokenLoaded) {
        token = credentialVault()?.read() ?? null;
        tokenLoaded = true;
    }
    const header: Record<string, string> = { Accept: 'application/json', 'X-Consumer-Page': page };
    if (language || native) header['Accept-Language'] = language || uni.getLocale();
    if (native && token) header.Authorization = `Bearer ${token}`;
    if (native && flow) header['X-Consumer-Flow'] = flow;
    if (!native && csrf) header['X-CSRF-TOKEN'] = csrf;
    return header;
}
function capture(header: Record<string, unknown> | undefined) {
    const csrfEntry = Object.entries(header ?? {}).find(([key]) => key.toLowerCase() === 'x-csrf-token');
    if (!native && typeof csrfEntry?.[1] === 'string') setCsrf(csrfEntry[1]);
    const entry = Object.entries(header ?? {}).find(
        ([key]) => key.toLowerCase() === 'x-consumer-flow',
    );
    if (native && typeof entry?.[1] === 'string') flow = entry[1];
}
export async function request<T>(
    path: string,
    method: 'GET' | 'POST' = 'GET',
    data?: Record<string, unknown>,
): Promise<T> {
    // Reopen an idle browser session before sending a form; never replay the mutation.
    if (!native && method === 'POST' && csrf && Date.now() - csrfUpdatedAt >= 60 * 60 * 1000) {
        const fresh = await request<{ csrfToken: string }>('/bootstrap');
        setCsrf(fresh.csrfToken);
    }
    await ensureLatestApp().catch(() => { throw new ApiError(426); });
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    });
    return new Promise((resolve, reject) =>
        uni.request({
            url: url(path),
            method,
            data,
            header: headers(),
            withCredentials: !native,
            timeout: ['/client/kyc/applications', '/client/kyc/recognize-front'].includes(path.split('?')[0]) ? 300000
                : path.split('?')[0] === '/client/cards/cardholder' ? 180000
                : path.split('?')[0] === '/support/messages' ? 60000
                : /^\/images\/direct\/[^/]+\/complete$/.test(path) ? 150000 : 20000,
            success(response) {
                capture(response.header);
                if (response.statusCode >= 200 && response.statusCode < 300)
                    resolve(response.data as T);
                else
                    reject(new ApiError(response.statusCode, response.data as ApiError['payload']));
            },
            fail() {
                reject(new ApiError(0));
            },
        }),
    );
}
type UploadTicket = { id: string; mode?: string; url: string; imageUrl?: string; fields: Record<string, string> };
export type UploadProgress = { stage: 'uploading' | 'submitting'; completed: number; total: number };
export type Upload = { name: string; path: string };
export async function upload<T>(
    path: string,
    data: Record<string, unknown>,
    files: Upload[],
    onProgress?: (progress: UploadProgress) => void,
): Promise<T> {
    await ensureLatestApp().catch(() => { throw new ApiError(426); });
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    });
    const purpose = ({
        '/client/kyc/applications': 'kyc',
        '/client/kyc/recognize-front': 'kyc',
        '/client/cards/cardholder': 'card',
        '/support/messages': 'support',
    } as Record<string, string>)[path.split('?')[0]] ?? (/^\/support-workspace\/conversations\/[a-f0-9-]{36}\/messages$/.test(path) ? 'support' : undefined);
    if (purpose && files.length) {
        const payload: Record<string, unknown> = { ...data };
        let completed = 0;
        onProgress?.({ stage: 'uploading', completed, total: files.length });
        const prepared: { file: Upload; ticket: UploadTicket }[] = [];
        for (const file of files) {
            const info = await new Promise<UniApp.GetImageInfoSuccessData>((resolve, reject) =>
                uni.getImageInfo({ src: file.path, success: resolve, fail: () => reject(new ApiError(422)) }),
            );
            const kind = info.type?.toLowerCase();
            let mime = kind === 'jpg' || kind === 'jpeg' ? 'image/jpeg'
                : kind === 'png' ? 'image/png' : kind === 'webp' ? 'image/webp' : '';
            // H5 getImageInfo supplies dimensions but no type; selected images are local blobs.
            if (!mime && !native && /^(blob:|data:image\/)/i.test(file.path)) {
                const blob = await fetch(file.path).then((response) => response.blob()).catch(() => {
                    throw new ApiError(422);
                });
                mime = blob.type.toLowerCase();
            }
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(mime)) throw new ApiError(422);
            const ticket = await request<UploadTicket>(
                '/images/direct', 'POST', { purpose, field: file.name, mime,
                    ...(path.split('?')[0] === '/client/kyc/recognize-front' ? { recognize_front: true } : {}),
                },
            );
            if (ticket.mode !== 'server' && !/^https:\/\/[a-z0-9.-]+\/?$/i.test(ticket.url)) throw new ApiError(502);
            prepared.push({ file, ticket });
        }
        const send = async ({ file, ticket }: typeof prepared[number]) => {
            if (ticket.mode === 'kyc_url') {
                if (purpose !== 'kyc' || !ticket.imageUrl || !/^https:\/\//i.test(ticket.imageUrl)) throw new ApiError(502);
                await new Promise<void>((resolve, reject) => uni.uploadFile({
                    url: ticket.url, filePath: file.path, name: 'file', formData: ticket.fields,
                    header: {}, timeout: 120000,
                    success: response => response.statusCode >= 200 && response.statusCode < 300
                        ? resolve() : reject(new ApiError(502)),
                    fail: () => reject(new ApiError(0)),
                }));
                payload[file.name + '_upload_id'] = ticket.id;
                payload[file.name + '_url'] = ticket.imageUrl;
                completed++;
                onProgress?.({ stage: 'uploading', completed, total: files.length });
                return;
            }
            // The private same-origin copy is validated before any business binding.
            await new Promise<void>((resolve, reject) => uni.uploadFile({
                url: url('/images/direct/' + ticket.id + '/backup'),
                filePath: file.path, name: 'file', header: headers(), timeout: 120000,
                success: response => response.statusCode >= 200 && response.statusCode < 300
                    ? resolve() : reject(new ApiError(response.statusCode)),
                fail: () => reject(new ApiError(0)),
            }));
            if (ticket.mode !== 'server') await new Promise<void>((resolve, reject) => uni.uploadFile({
                url: ticket.url,
                filePath: file.path,
                name: 'file',
                formData: ticket.fields,
                // Never forward API bearer tokens, session cookies or business fields to OSS.
                header: {},
                timeout: 120000,
                success: (response) => response.statusCode >= 200 && response.statusCode < 300
                    ? resolve() : reject(new ApiError(502)),
                fail: () => reject(new ApiError(0)),
            })).catch(() => { /* Server copy remains available; completion records OSS retry. */ });
            await request('/images/direct/' + ticket.id + '/complete', 'POST');
            payload[file.name + '_upload_id'] = ticket.id;
            completed++;
            onProgress?.({ stage: 'uploading', completed, total: files.length });
        };
        const concurrency = purpose === 'kyc' && prepared.every(({ ticket }) => ticket.mode === 'kyc_url') ? 2 : 1;
        for (let index = 0; index < prepared.length; index += concurrency) {
            const results = await Promise.allSettled(prepared.slice(index, index + concurrency).map(send));
            const failed = results.find((result): result is PromiseRejectedResult => result.status === 'rejected');
            if (failed) throw failed.reason;
        }
        onProgress?.({ stage: 'submitting', completed, total: files.length });
        // Business submission is still authenticated and never replayed automatically.
        return request<T>(path, 'POST', payload);
    }
    return new Promise((resolve, reject) =>
        uni.uploadFile({
            url: url(path),
            header: headers(),
            timeout: ['/client/kyc/applications', '/client/kyc/recognize-front'].includes(path.split('?')[0]) ? 300000 : 60000,
            formData: Object.fromEntries(Object.entries(data).map(([key, value]) => [key, typeof value === 'boolean' ? (value ? '1' : '0') : String(value ?? '')])),
            files: files.map((file) => ({ name: file.name, uri: file.path })),
            success(response) {
                let result;
                try {
                    result = JSON.parse(response.data);
                } catch {
                    reject(new ApiError(502));
                    return;
                }
                if (response.statusCode >= 200 && response.statusCode < 300) resolve(result as T);
                else reject(new ApiError(response.statusCode, result));
            },
            fail() {
                reject(new ApiError(0));
            },
        }),
    );
}
export function photoUrl(value: string | null) {
    if (!value) return '';
    const origin = companyOrigin();
    if (/[\\\r\n]/.test(value)) return '';
    // These URLs come only from the server's branding DTO. OSS images are public,
    // rendered without API Authorization or flow headers.
    if (/^https:\/\/[a-z0-9.-]+(?::\d+)?\/[^\s]*$/i.test(value) && !value.startsWith(origin + '/')) return value;
    const path = value.startsWith(origin + '/') ? value.slice(origin.length) : value;
    if (!path.startsWith('/') || path.startsWith('//')) return '';
    return native ? origin + path : path;
}
export async function privateImage(path: string): Promise<string> {
    if (
        !/^\/(?:support|support-workspace)\/images\/[a-f0-9-]{36}$/i.test(path) &&
        path !== '/client/promotion/poster-background'
    )
        return Promise.reject(new ApiError(404));
    await ensureLatestApp().catch(() => { throw new ApiError(426); });
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    });
    const url = (native ? companyOrigin() + '/api/mobile/v1' : '/api/v1') + path;
    const header = headers();
    return new Promise((resolve, reject) =>
        uni.downloadFile({
            url,
            header,
            success(result) {
                if (result.statusCode === 200) resolve(result.tempFilePath);
                else reject(new ApiError(result.statusCode));
            },
            fail() {
                reject(new ApiError(0));
            },
        }),
    );
}
export { company };
