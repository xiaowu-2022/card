import { credentialRevision } from './browser-credentials';
// Titles carry only lifecycle state and a non-secret mailbox revision.
export function notifyWebviewSession(signedIn: boolean, tenantId?: string, signedOut = false, expired = false) {
    // #ifdef H5
    if (typeof window === 'undefined' || !tenantId) return;
    const state = { signedIn, tenantId, signedOut, credentialRevision, ...(expired ? { expired: true } : {}) };
    (window as typeof window & { __consumerSessionState?: typeof state }).__consumerSessionState = state;
    window.dispatchEvent(new CustomEvent('consumer-session-state', { detail: state }));
    // #endif
}
