import { company } from './api';
// All business URLs stay inside uni-app. Only explicitly registered consumer paths resolve.
const direct: Record<string, string> = {
    '/login': '/pages/login/index',
    '/dashboard': '/pages/assets/index',
    '/cards': '/pages/cards/index',
    '/account': '/pages/account/index',
    '/messages': '/pages/messages/index',
    '/support': '/pages/support/index',
};
export function internalUrl(path: string) {
    if (/^https?:\/\//i.test(path)) {
        const parsed = /^(https?:\/\/[^/]+)(\/.*)?$/i.exec(path);
        if (!parsed) throw new Error('Invalid route');
        const allowed = [company.apiOrigin.replace(/\/$/, '')];
        // #ifdef H5
        if (typeof window !== 'undefined') allowed.push(window.location.origin);
        // #endif
        if (!allowed.includes(parsed[1])) throw new Error('External route rejected');
        path = parsed[2] || '/';
    }
    const anchor = path.includes('#') ? path.slice(path.indexOf('#') + 1) : '';
    if (anchor && !/^[a-z][a-z0-9-]*$/i.test(anchor)) throw new Error('Invalid anchor');
    path = path.split('#')[0];
    path = path.replace(/^\/api\/(?:mobile\/)?v1\/client(?=\/|$)/, '') || '/';
    if (
        !path.startsWith('/') ||
        path.startsWith('//') ||
        path.includes('..') ||
        /[\\#\r\n]/.test(path)
    )
        throw new Error('Invalid route');
    const [base, query] = path.split('?');
    if (direct[base]) {
        const params = [query, anchor ? '_anchor=' + encodeURIComponent(anchor) : '']
            .filter(Boolean)
            .join('&');
        return direct[base] + (params ? '?' + params : '');
    }
    if (/^\/messages\/[a-f\d-]{36}$/.test(base))
        return '/pages/messages/detail?id=' + base.split('/').pop();
    return '/pages/screen/index?path=' + encodeURIComponent(path + (anchor ? '#' + anchor : ''));
}
export function go(path: string, replace = false) {
    const url = internalUrl(path);
    if (replace) uni.redirectTo({ url });
    else uni.navigateTo({ url });
}
export function home(path = '/dashboard') {
    uni.reLaunch({ url: internalUrl(path) });
}
export function back(fallback = '/account') {
    if (getCurrentPages().length > 1) uni.navigateBack();
    else home(fallback);
}
