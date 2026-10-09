export type PosterBridge = { version: number; save(data: string): Promise<void>; cancel?(): void };
export function inWebview(): boolean {
    return (typeof location !== 'undefined' && new URLSearchParams(location.search).get('app_webview') === '1')
        || (typeof navigator !== 'undefined' && /Html5Plus|uni-app/i.test(navigator.userAgent));
}
// iOS WebViews already support the original download / image context-menu path.
// Do not replace that working path with the shell title bridge or a UA permission gate.
export function isIOSWebview(): boolean {
    return inWebview() && typeof navigator !== 'undefined'
        && (/iPhone|iPad|iPod/i.test(navigator.userAgent)
            || (/Macintosh/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1));
}
export async function saveBrowserPoster(data: string, code: string): Promise<'saved' | 'download'> {
    const bridge = (window as Window & { __specpayPoster?: PosterBridge }).__specpayPoster;
    if (!isIOSWebview() && bridge?.version === 1) {
        // Older installed shells may never settle their bridge callback.
        await boundedPosterSave(() => bridge.save(data), () => bridge.cancel?.());
        return 'saved';
    }
    if (inWebview() && !isIOSWebview()) throw new Error('update-required');
    const link = document.createElement('a');
    link.href = data;
    link.download = 'invitation-' + code.replace(/[^a-z0-9-]/gi, '') + '.png';
    document.body.appendChild(link);
    link.click();
    link.remove();
    return 'download';
}

export function boundedPosterSave(save: () => Promise<void>, cancel?: () => void, timeout = 125000): Promise<void> {
    return new Promise((resolve, reject) => {
        const timer = setTimeout(() => {
            reject(new Error('timeout'));
            try { cancel?.(); } catch { /* A stale native bridge may already be gone. */ }
        }, timeout);
        Promise.resolve().then(save).then(resolve, reject).finally(() => clearTimeout(timer));
    });
}
