// Browser credentials stay on the current origin. WebView credentials remain
// memory-only here; the owning shell persists them in OS-protected storage.
const key = 'consumer-bearer-v1';
const validToken = (value: unknown): value is string => typeof value === 'string' && /^[1-9][0-9]{0,17}\|[A-Za-z0-9]{64}$/.test(value);
type Bridge = { channel: string; token: string | null; tenantId: string };
type BridgeWindow = Window & { __consumerTokenBridge?: Bridge; __consumerSessionState?: { signedIn: boolean; tenantId: string; signedOut: boolean; pending: boolean } };
export const browserWebview = import.meta.env.UNI_PLATFORM === 'h5' && typeof location !== 'undefined'
    && new URLSearchParams(location.search).get('app_webview') === '1';
let bridge: Bridge | null = null;
let waiting: Promise<Bridge> | null = null;
export let credentialRevision = 0;
function shellBridge(): Promise<Bridge> {
    if (bridge) return Promise.resolve(bridge);
    if (waiting) return waiting;
    waiting = new Promise<Bridge>((resolve, reject) => {
        const receive = () => {
            const value = (window as BridgeWindow).__consumerTokenBridge;
            if (!value || !/^[A-Za-z0-9]{64}$/.test(value.channel) || typeof value.tenantId !== 'string'
                || !value.tenantId || (value.token !== null && !validToken(value.token))) return;
            bridge = { ...value };
            // Remove the initial credential from the global handshake object.
            value.token = null;
            cleanup(); resolve(bridge);
        };
        const cleanup = () => { clearTimeout(timer); window.removeEventListener('consumer-token-bridge', receive); };
        const timer = setTimeout(() => { cleanup(); reject(new Error('Native credential bridge unavailable')); }, 15000);
        window.addEventListener('consumer-token-bridge', receive);
        receive();
    }).finally(() => { waiting = null; });
    return waiting;
}
export async function loadBrowserToken(): Promise<string | null> {
    if (browserWebview) return (await shellBridge()).token;
    // Storage failures are visible recovery errors, never silently a guest session.
    const value = localStorage.getItem(key);
    if (value === null) return null;
    if (!validToken(value)) throw new Error('Invalid saved credential');
    return value;
}
export function saveBrowserToken(value: string | null) {
    if (value !== null && !validToken(value)) throw new Error('Invalid credential');
    if (browserWebview) {
        if (bridge) {
            if (value !== null && value !== bridge.token) {
                // Fence the previous native identity before a new account's full
                // bootstrap/publication finishes. A reconnect must not restore it.
                const state = { signedIn: false, tenantId: bridge.tenantId, signedOut: false, pending: true };
                (window as BridgeWindow).__consumerSessionState = state;
                window.dispatchEvent(new CustomEvent('consumer-session-state', { detail: state }));
            }
            bridge.token = value;
        }
        return;
    }
    if (value === null) localStorage.removeItem(key);
    else localStorage.setItem(key, value);
}
export function onBrowserTokenChange(receive: (value: string | null) => void) {
    if (import.meta.env.UNI_PLATFORM !== 'h5' || browserWebview || typeof window === 'undefined') return;
    window.addEventListener('storage', event => {
        if (event.storageArea !== localStorage || (event.key !== key && event.key !== null)) return;
        const current = localStorage.getItem(key);
        if (current !== null && !validToken(current)) return;
        // Read the latest value; queued storage events must not restore an old account.
        receive(current);
    });
}
export async function assertBrowserCompany(tenantId: string) {
    if (browserWebview && (await shellBridge()).tenantId !== tenantId) throw new Error('Company changed');
}
export async function publishBrowserToken(token: string | null, tenantId: string) {
    if (!browserWebview) return;
    const current = await shellBridge();
    if (current.tenantId !== tenantId) throw new Error('Company changed');
    if (!token) { credentialRevision = 0; return; }
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 15000);
    try {
        const response = await fetch('/api/mobile/v1/session/bridge/publish', {
            method: 'POST', credentials: 'omit', cache: 'no-store',
            headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'X-Consumer-Bridge': current.channel },
            signal: controller.signal,
        });
        if (!response.ok) throw new Error('Native credential save unavailable');
        const result = await response.json();
        if (!Number.isSafeInteger(result.revision) || result.revision < 1) throw new Error('Invalid native credential receipt');
        credentialRevision = result.revision;
    } finally { clearTimeout(timer); }
}
