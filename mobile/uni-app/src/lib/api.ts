import company from '../generated/company.json';
export const native = import.meta.env.UNI_PLATFORM === 'app';
// Until the native Keychain/Keystore bridge is independently verified, App tokens
// stay in memory. Never downgrade credentials to uni storage/localStorage.
let token: string | null = null;
let csrf: string | null = null;
export let sessionGeneration = 0;
export function setToken(value: string | null) { token = value; sessionGeneration++; }
export function setCsrf(value: string | null) { csrf = value; }
export class ApiError extends Error {
    constructor(public status: number) { super('Request failed'); }
}
export function request<T>(path: string, method: 'GET' | 'POST' = 'GET', data?: Record<string, unknown>): Promise<T> {
    if (!/^\/[a-z0-9/?=&_-]+$/i.test(path) || path.startsWith('//')) return Promise.reject(new Error('Invalid API path'));
    const origin = native ? company.apiOrigin : '';
    const prefix = native ? '/api/mobile/v1' : '/api/v1';
    const header: Record<string, string> = { Accept: 'application/json' };
    if (native && token) header.Authorization = `Bearer ${token}`;
    if (!native && csrf) header['X-CSRF-TOKEN'] = csrf;
    return new Promise((resolve, reject) => uni.request({
        url: `${origin}${prefix}${path}`, method, data, header,
        withCredentials: !native, timeout: 20000,
        success(response) {
            if (response.statusCode >= 200 && response.statusCode < 300) resolve(response.data as T);
            else reject(new ApiError(response.statusCode));
        },
        fail() { reject(new ApiError(0)); },
    }));
}
export function photoUrl(value: string | null) {
    if (!value) return '';
    const url = new URL(value, company.apiOrigin);
    if (url.origin !== new URL(company.apiOrigin).origin) return '';
    return native ? url.href : `${url.pathname}${url.search}`;
}
export function privateImage(path: string): Promise<string> {
    if (!/^\/support\/images\/[a-f0-9-]{36}$/i.test(path)) return Promise.reject(new ApiError(404));
    const url = (native ? company.apiOrigin + '/api/mobile/v1' : '/api/v1') + path;
    const header: Record<string, string> = {};
    if (native && token) header.Authorization = `Bearer ${token}`;
    return new Promise((resolve, reject) => uni.downloadFile({ url, header,
        success(result) { if (result.statusCode === 200) resolve(result.tempFilePath); else reject(new ApiError(result.statusCode)); },
        fail() { reject(new ApiError(0)); },
    }));
}
export { company };
