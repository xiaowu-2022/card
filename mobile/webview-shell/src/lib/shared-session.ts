import { origin, type DirectoryCache } from './directory';

export const rememberCookie = 'consumer_remember';
// Laravel's encrypted/URL-encoded cookie value only, never a Set-Cookie string.
export const validRememberCookie = (value: string) => /^[A-Za-z0-9%+/=_-]{32,8192}$/.test(value);
export function readRememberCookie(header: string): string | null {
    const values = header.split(';').map(part => part.trim()).filter(part => part.startsWith(rememberCookie + '='));
    if (values.length !== 1) return null;
    const value = values[0].slice(rememberCookie.length + 1);
    return validRememberCookie(value) ? value : null;
}
export function pageOrigin(url: string): string | null {
    return origin(url.match(/^https:\/\/[^/?#]+/i)?.[0]);
}
type Metadata = { initialized: boolean; source: string | null; signedIn: boolean };
export function sharedSession(ports: {
    cookies: { get(url: string): string; set(url: string, value: string): void };
    // Android: AES-GCM/Keystore. iOS: retain credentials only in its native
    // HttpOnly cookie store; metadata never contains a credential.
    vault: { read(): string | null; write(value: string | null): void } | null;
    metadata: { get(): Metadata | null; set(value: Metadata): void };
}) {
    let directory: DirectoryCache | null = null;
    let credential: string | null = null;
    let initialized = false;
    const trusted = (url: string) => !!directory && directory.origins.includes(url) && origin(url) === url;
    function remember(source: string | null, signedIn: boolean) {
        ports.metadata.set({ initialized: true, source, signedIn });
    }
    function forget() {
        credential = null;
        remember(null, false);
        ports.vault?.write(null);
    }
    function capture(url: string) {
        if (!trusted(url)) return false;
        try {
            const value = readRememberCookie(ports.cookies.get(url + '/'));
            if (!value) { forget(); return false; }
            if (credential !== value) ports.vault?.write(value);
            credential = value;
            remember(url, true);
        } catch (error) {
            // Never retain an earlier account if saving the current one fails.
            credential = null;
            try { forget(); } catch { /* memory is already cleared */ }
            throw error;
        }
        return true;
    }
    function clear() {
        // Persist the logout marker before touching the vault/cookie store so a
        // delayed response or failed native deletion cannot resurrect it.
        try { forget(); } finally {
            for (const url of directory?.origins ?? []) {
                ports.cookies.set(url + '/', rememberCookie + '=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; HttpOnly; SameSite=Lax');
            }
        }
    }
    return {
        configure(value: DirectoryCache, previousOrigin?: string) {
            if (directory && directory.tenantId !== value.tenantId) throw new Error('Company changed');
            directory = value;
            if (initialized) return;
            initialized = true;
            const meta = ports.metadata.get();
            if (meta?.initialized && !meta.signedIn) return;
            credential = ports.vault?.read() ?? null;
            if (credential && !validRememberCookie(credential)) credential = null;
            // Adopt an existing installation once. iOS restores from the last
            // verified host's native cookie store, not JS/localStorage.
            const source = meta?.source ?? previousOrigin;
            if (!credential && (!ports.vault || !meta?.initialized) && source && trusted(source)) capture(source);
        },
        capture,
        clear,
        async restore(url: string) {
            if (!trusted(url)) throw new Error('Unverified login destination');
            const value = credential;
            const cookie = value
                ? `${rememberCookie}=${value}; Path=/; Max-Age=2592000; Secure; HttpOnly; SameSite=Lax`
                : `${rememberCookie}=; Path=/; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Secure; HttpOnly; SameSite=Lax`;
            ports.cookies.set(url + '/', cookie);
            // Some runtimes commit native cookie writes asynchronously. Do not
            // start the destination H5 before the intended credential is ready.
            for (let attempt = 0; attempt < 20; attempt++) {
                if (readRememberCookie(ports.cookies.get(url + '/')) === value) return;
                await new Promise(resolve => setTimeout(resolve, 50));
            }
            throw new Error('登录状态恢复失败，请重试。');
        },
    };
}
