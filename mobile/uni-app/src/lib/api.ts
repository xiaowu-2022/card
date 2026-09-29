import company from '../generated/company.json';
import { companyOrigin, ensureCompanyOrigin } from './origin';
export const native = import.meta.env.UNI_PLATFORM === 'app';
// Until the native Keychain/Keystore bridge is independently verified, App tokens
// stay in memory. Never downgrade credentials to uni storage/localStorage.
let token: string | null = null;
let csrf: string | null = null;
let flow: string | null = null;
let language = '';
let page = '/';
export let sessionGeneration = 0;
export function setToken(value: string | null) {
    token = value;
    sessionGeneration++;
}
export function setCsrf(value: string | null) {
    csrf = value;
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
    const header: Record<string, string> = { Accept: 'application/json', 'X-Consumer-Page': page };
    if (language || native) header['Accept-Language'] = language || uni.getLocale();
    if (native && token) header.Authorization = `Bearer ${token}`;
    if (native && flow) header['X-Consumer-Flow'] = flow;
    if (!native && csrf) header['X-CSRF-TOKEN'] = csrf;
    return header;
}
function capture(header: Record<string, unknown> | undefined) {
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
            timeout: path.split('?')[0] === '/client/kyc/applications' ? 300000
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
export type Upload = { name: string; path: string };
export async function upload<T>(
    path: string,
    data: Record<string, string>,
    files: Upload[],
): Promise<T> {
    await ensureCompanyOrigin().catch(() => {
        throw new ApiError(0);
    });
    const purpose = ({
        '/client/kyc/applications': 'kyc',
        '/client/cards/cardholder': 'card',
        '/support/messages': 'support',
    } as Record<string, string>)[path.split('?')[0]];
    if (purpose && files.length) {
        const payload: Record<string, string> = { ...data };
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
            const ticket = await request<{ id: string; url: string; imageUrl?: string; fields: Record<string, string> }>(
                '/images/direct', 'POST', { purpose, field: file.name, mime },
            );
            if (!/^https:\/\/[a-z0-9.-]+\/?$/i.test(ticket.url)) throw new ApiError(502);
            await new Promise<void>((resolve, reject) => uni.uploadFile({
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
            }));
            if (purpose === 'kyc') {
                if (!ticket.imageUrl || !/^https:\/\/[^\s]+$/.test(ticket.imageUrl)) throw new ApiError(502);
                payload[file.name + '_url'] = ticket.imageUrl;
            } else {
                await request('/images/direct/' + ticket.id + '/complete', 'POST');
            }
            payload[file.name + '_upload_id'] = ticket.id;
        }
        // Business submission is still authenticated and never replayed automatically.
        return request<T>(path, 'POST', payload);
    }
    return new Promise((resolve, reject) =>
        uni.uploadFile({
            url: url(path),
            header: headers(),
            timeout: path.split('?')[0] === '/client/kyc/applications' ? 300000 : 60000,
            formData: data,
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
        !/^\/support\/images\/[a-f0-9-]{36}$/i.test(path) &&
        path !== '/client/promotion/poster-background'
    )
        return Promise.reject(new ApiError(404));
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
