import { internalUrl } from './navigation';
// Preserve links distributed before migration (including invitation parameters).
// H5 keeps a hash router for static hosting; all resulting pages are uni-app pages.
export function normalizeWebEntry() {
    // #ifdef H5
    if (typeof window === 'undefined' || window.location.hash.startsWith('#/pages/')) return;
    const path = window.location.pathname;
    if (path === '/' || path.endsWith('/index.html')) return;
    if (
        !/^\/(?:login|register|forgot-password|dashboard|account|cards|wallet|funds|assets|security-deposit|wealth|promotion|messages|support|about)(?:\/|$)/.test(
            path,
        )
    )
        return;
    window.history.replaceState(
        null,
        '',
        '/#' + internalUrl(path + window.location.search + window.location.hash),
    );
    // #endif
}
