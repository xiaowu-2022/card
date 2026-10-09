import { internalUrl } from './navigation';
import { webBase } from './origin';
// Preserve links distributed before migration (including invitation parameters).
// H5 keeps a hash router for static hosting; all resulting pages are uni-app pages.
export function normalizeWebEntry() {
    // #ifdef H5
    if (typeof window === 'undefined' || window.location.hash.startsWith('#/pages/')) return;
    const base = webBase();
    const pathname = window.location.pathname;
    const path = base !== '/' && pathname.startsWith(base) ? '/' + pathname.slice(base.length) : pathname;
    if (path === '/' || path.endsWith('/index.html')) {
        // The static invitation launcher forwards its query to the chosen H5 root.
        const invite = new URLSearchParams(window.location.search).get('invite');
        if (invite) window.history.replaceState(null, '',
            base + '#' + internalUrl('/register?invite=' + encodeURIComponent(invite)));
        return;
    }
    if (
        !/^\/(?:login|register|forgot-password|dashboard|account|cards|wallet|funds|assets|security-deposit|wealth|promotion|messages|support|about)(?:\/|$)/.test(
            path,
        )
    )
        return;
    window.history.replaceState(
        null,
        '',
        base + '#' + internalUrl(path + window.location.search + window.location.hash),
    );
    // #endif
}
