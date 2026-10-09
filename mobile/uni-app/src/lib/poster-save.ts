export type PosterBridge = { version: number; save(data: string): Promise<void>; cancel?(): void };
export function inWebview(): boolean {
    return (typeof location !== 'undefined' && new URLSearchParams(location.search).get('app_webview') === '1')
        || (typeof navigator !== 'undefined' && /Html5Plus|uni-app/i.test(navigator.userAgent));
}
export function isIOSDevice(): boolean {
    return typeof navigator !== 'undefined'
        && (/iPhone|iPad|iPod/i.test(navigator.userAgent)
            || (/Macintosh/i.test(navigator.userAgent) && navigator.maxTouchPoints > 1));
}
export function isIOSWebview(): boolean {
    return inWebview() && isIOSDevice();
}
export async function saveBrowserPoster(data: string, code: string): Promise<'saved' | 'download' | 'shared' | 'manual' | 'cancelled'> {
    const filename = 'invitation-' + code.replace(/[^a-z0-9-]/gi, '') + '.png';
    if (isIOSDevice()) {
        // Build synchronously so the share sheet retains the button's user activation.
        if (typeof File !== 'undefined' && navigator.canShare && navigator.share) {
            try {
                const bytes = Uint8Array.from(atob(data.split(',')[1]), char => char.charCodeAt(0));
                const file = new File([bytes], filename, { type: 'image/png' });
                if (navigator.canShare({ files: [file] })) {
                    await navigator.share({ files: [file] });
                    // A completed share is not proof that the user saved to Photos.
                    return 'shared';
                }
            } catch (error) {
                if (error && typeof error === 'object' && 'name' in error && error.name === 'AbortError') return 'cancelled';
            }
        }
        return 'manual';
    }
    const bridge = (window as Window & { __specpayPoster?: PosterBridge }).__specpayPoster;
    if (!isIOSWebview() && bridge?.version === 1) {
        // Older installed shells may never settle their bridge callback.
        await boundedPosterSave(() => bridge.save(data), () => bridge.cancel?.());
        return 'saved';
    }
    if (inWebview() && !isIOSWebview()) throw new Error('update-required');
    const link = document.createElement('a');
    link.href = data;
    link.download = filename;
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
