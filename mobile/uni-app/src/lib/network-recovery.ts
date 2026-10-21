import { t } from './i18n';
/** Document-local recovery UI. No credentials, API payloads or URLs are persisted. */
const key = 'consumer-network-recovery-v1';
let blocked = false, storageAvailable = true, failed = false;
let layer: HTMLDivElement | null = null;
let revision = 0, dismissed = false, reloading = false, uncertain = false;
try { blocked = sessionStorage.getItem(key) === 'pending'; }
catch { storageAvailable = false; blocked = true; }
function removeLayer() { layer?.remove(); layer = null; }
function refresh() {
    if (!storageAvailable || reloading) return;
    try {
        sessionStorage.setItem(key, 'pending');
        if (sessionStorage.getItem(key) !== 'pending') throw new Error();
    } catch { storageAvailable = false; blocked = true; removeLayer(); show(true); return; }
    blocked = true; reloading = true;
    // Remove the button synchronously: repeated clicks cannot reload twice.
    removeLayer();
    window.location.reload();
}
function show(retry: boolean, mutation = false) {
    if (layer || document.hidden) return;
    layer = document.createElement('div');
    layer.setAttribute('role', retry ? 'status' : 'alertdialog');
    if (!retry) layer.setAttribute('aria-modal', 'true');
    layer.setAttribute('aria-label', t('Connection unavailable'));
    layer.style.cssText = retry
        ? 'position:fixed;left:16px;right:16px;bottom:calc(16px + env(safe-area-inset-bottom));z-index:2147483647;display:flex;justify-content:center;filter:drop-shadow(0 4px 16px #0003)'
        : 'position:fixed;inset:0;z-index:2147483647;background:#0007;display:flex;align-items:center;justify-content:center;padding:24px';
    const panel = document.createElement('div');
    panel.style.cssText = 'background:white;color:#171c19;border-radius:20px;padding:24px;max-width:380px;width:100%;font:16px/1.7 sans-serif';
    const text = document.createElement('p');
    text.textContent = t(retry ? 'The service is still unavailable. Check your connection and retry.' : 'Cannot connect to the server. Refresh the page to try again.')
        + (mutation ? ' ' + t('The operation result is unknown. Check your records before submitting again.') : '')
        + (!storageAvailable ? ' ' + t('Refresh protection storage is unavailable. Refresh manually or close and reopen this page.') : '');
    panel.append(text);
    if (storageAvailable) {
        const button = document.createElement('button');
        button.textContent = t(retry ? 'Retry refresh' : 'Refresh page');
        button.style.cssText = 'padding:12px 24px;border:0;border-radius:24px;background:#171c19;color:white;font:inherit';
        button.onclick = () => { button.disabled = true; refresh(); };
        panel.append(button);
    }
    const close = document.createElement('button');
    close.textContent = t('Keep current page');
    close.style.cssText = 'margin-left:12px;padding:12px;border:0;background:transparent;font:inherit';
    close.onclick = () => { blocked = true; dismissed = true; removeLayer(); };
    panel.append(close);
    layer.append(panel);
    document.body.append(layer);
}
export const networkRecovery = {
    failure(mutation = false) {
        if (typeof document === 'undefined') return;
        failed = true; revision++;
        if (mutation && !uncertain && layer) {
            const warning = document.createElement('p');
            warning.textContent = t('The operation result is unknown. Check your records before submitting again.');
            layer.firstElementChild?.append(warning);
        }
        uncertain ||= mutation;
        if (!dismissed) show(blocked, uncertain);
    },
    checkpoint() { return revision; },
    success(started: number) {
        if (started !== revision || reloading) return;
        if (!failed && !blocked) return;
        // Concurrent successful fragments must not hide another fragment's failure.
        const observed = revision;
        setTimeout(() => {
            if (revision !== observed || reloading) return;
            removeLayer();
            try { sessionStorage.removeItem(key); } catch { return; }
            failed = blocked = dismissed = uncertain = false;
        }, 1000);
    },
};
