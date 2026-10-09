export type PosterBridge = { version: number; save(data: string): Promise<void> };
export function inWebview(): boolean {
    return (typeof location !== 'undefined' && new URLSearchParams(location.search).get('app_webview') === '1')
        || (typeof navigator !== 'undefined' && /Html5Plus|uni-app/i.test(navigator.userAgent));
}
export async function saveBrowserPoster(data: string, code: string): Promise<'saved' | 'download'> {
    const bridge = (window as Window & { __specpayPoster?: PosterBridge }).__specpayPoster;
    if (bridge?.version === 1) { await bridge.save(data); return 'saved'; }
    if (inWebview()) throw new Error('update-required');
    const link = document.createElement('a');
    link.href = data;
    link.download = 'invitation-' + code.replace(/[^a-z0-9-]/gi, '') + '.png';
    document.body.appendChild(link);
    link.click();
    link.remove();
    return 'download';
}
