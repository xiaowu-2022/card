import { shallowRef } from 'vue';
import company from '../generated/company.json';
import { DomainRouter } from './domain-routing';

const native = import.meta.env.UNI_PLATFORM === 'app';
const cacheKey = `company-domains:${company.appId}:${company.tenantSlug}:${company.apiOrigin}`;
let cached: unknown = [];
if (native) {
    try {
        cached = uni.getStorageSync(cacheKey);
    } catch {
        /* optional cache */
    }
}
const router = new DomainRouter(
    company.apiOrigins,
    company.tenantSlug,
    company.developmentOnly,
    (origin) =>
        new Promise((resolve, reject) =>
            uni.request({
                url: origin + '/api/mobile/v1/domains',
                method: 'GET',
                header: { Accept: 'application/json' },
                withCredentials: false,
                timeout: 4000,
                success: (response) =>
                    response.statusCode === 200
                        ? resolve(response.data)
                        : reject(new Error('Domain unavailable')),
                fail: reject,
            }),
        ),
    cached,
    (origins) => uni.setStorageSync(cacheKey, origins),
);
export async function ensureCompanyOrigin() {
    if (native) await router.ready();
}
export async function refreshCompanyOrigins() {
    if (native) await router.refresh();
}

export function companyOrigin(): string {
    // H5 keeps host-only cookies; only native apps select a verified company origin.
    if (import.meta.env.UNI_PLATFORM === 'h5') return window.location.origin;
    if (!router.selected) throw new Error('Company domain is not ready');
    return router.selected;
}

export function webBase(): string {
    return import.meta.env.UNI_PLATFORM === 'h5'
        ? new URL(import.meta.env.BASE_URL, window.location.href).pathname
        : '/';
}

function validAssets(value: unknown): Record<string, string> {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return {};
    return Object.fromEntries(Object.entries(value).filter(([path, url]) => {
        if (!path.startsWith('/') || typeof url !== 'string') return false;
        try {
            const parsed = new URL(url);
            return parsed.protocol === 'https:' && !parsed.username && !parsed.password;
        } catch { return false; }
    })) as Record<string, string>;
}
const publicAssets = shallowRef<Record<string, string>>(
    import.meta.env.UNI_PLATFORM === 'h5'
        ? validAssets((window as unknown as { __PUBLIC_ASSETS__?: unknown }).__PUBLIC_ASSETS__)
        : {},
);
export function setPublicAssets(value: unknown = {}) {
    // A deployment may serve its versioned entry before the API manifest is published.
    // Keep the entry's complete artwork map; valid newer URLs override individual keys.
    publicAssets.value = { ...publicAssets.value, ...validAssets(value) };
}
export function staticAsset(path: string): string {
    const remote = publicAssets.value['/' + path];
    if (remote) return remote;
    // Native startup can be offline. H5 must never silently request local artwork.
    return native ? '/static/' + path : '';
}

export function invitationUrl(code: string): string {
    const path = '/register?invite=' + encodeURIComponent(code);
    if (import.meta.env.UNI_PLATFORM === 'h5')
        return (
            companyOrigin() + webBase() + '#/pages/screen/index?path=' + encodeURIComponent(path)
        );
    return companyOrigin() + path;
}
