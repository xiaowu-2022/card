// This is a non-secret lifecycle notification, not a native API bridge.
// Credentials remain HttpOnly and are read only by the owning native shell.
export function notifyWebviewSession(signedIn: boolean, tenantId?: string) {
    // #ifdef H5
    if (typeof window === 'undefined' || !tenantId) return;
    const state = { signedIn, tenantId };
    (window as typeof window & { __consumerSessionState?: typeof state }).__consumerSessionState = state;
    window.dispatchEvent(new CustomEvent('consumer-session-state', { detail: state }));
    // #endif
}
