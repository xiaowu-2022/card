import { companyOrigin } from './origin';
import { consumerSessionScope } from './session';
import { t } from './i18n';
// All business URLs stay inside uni-app. Only explicitly registered consumer paths resolve.
const direct: Record<string, string> = {
    '/app-download': '/pages/app-download/index',
    '/login': '/pages/login/index',
    '/dashboard': '/pages/assets/index',
    '/cards': '/pages/cards/index',
    '/account': '/pages/account/index',
    '/messages': '/pages/messages/index',
    '/support': '/pages/support/index',
    '/support-workspace': '/pages/support-workspace/index',
    '/support-workspace/replies': '/pages/support-workspace/replies',
    '/support-workspace/customer': '/pages/support-workspace/customer',
    '/support-workspace/chat': '/pages/support/index',
};
export function internalUrl(path: string) {
    if (/^https?:\/\//i.test(path)) {
        const parsed = /^(https?:\/\/[^/]+)(\/.*)?$/i.exec(path);
        if (!parsed) throw new Error('Invalid route');
        const allowed = [companyOrigin()];
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
const rootTabs = new Set(['/pages/assets/index', '/pages/cards/index', '/pages/account/index']);
let tabNavigationGeneration = 0;
const tabCode = new Map<string, Promise<unknown>>();
function loadTabCode(url: string): Promise<unknown> {
    // #ifdef H5
    const previous = tabCode.get(url);
    if (previous) return previous;
    const load =
        url === '/pages/assets/index'
            ? import('../pages/assets/index.vue')
            : url === '/pages/cards/index'
              ? import('../pages/cards/index.vue')
              : import('../pages/account/index.vue');
    tabCode.set(url, load);
    void load.catch(() => {
        if (tabCode.get(url) === load) tabCode.delete(url);
    });
    return load;
    // #endif
    return Promise.resolve();
}
function switchHomeTab(url: string): boolean {
    // #ifdef H5
    if (rootTabs.has(url)) {
        const pages = getCurrentPages();
        const current = pages[pages.length - 1] as any;
        if ('/' + current?.route !== url) {
            const run = tabNavigationGeneration,
                scope = consumerSessionScope(),
                from = window.location.hash;
            // switchTab hides the old page immediately: load target code before invoking it.
            void loadTabCode(url)
                .then(() => {
                    if (
                        run !== tabNavigationGeneration ||
                        (scope && scope !== consumerSessionScope()) ||
                        from !== window.location.hash
                    )
                        return;
                    uni.switchTab({
                        url,
                        fail: () => {
                            uni.showToast({
                                title: t('Unable to load. Please try again.'),
                                icon: 'none',
                            });
                        },
                    });
                })
                .catch(() => {
                    if (run === tabNavigationGeneration)
                        uni.showToast({
                            title: t('Unable to load. Please try again.'),
                            icon: 'none',
                        });
                });
        }
        return true;
    }
    if (rootTabs.has(url.split('?')[0])) {
        // Deep links carry onLoad parameters; preserve those instead of dropping them.
        uni.reLaunch({ url });
        return true;
    }
    // #endif
    return false;
}
export function go(path: string, replace = false) {
    tabNavigationGeneration++;
    const url = internalUrl(path);
    if (switchHomeTab(url)) return;
    if (replace) uni.redirectTo({ url });
    else uni.navigateTo({ url });
}
export function home(path = '/dashboard') {
    tabNavigationGeneration++;
    const url = internalUrl(path);
    if (!switchHomeTab(url)) uni.reLaunch({ url });
}
export function back(fallback = '/account') {
    tabNavigationGeneration++;
    if (getCurrentPages().length > 1) uni.navigateBack();
    else home(fallback);
}

let tabWarmupScheduled = false;
/** Load code only; do not mount hidden pages or request account/financial data. */
export function warmH5Tabs() {
    // #ifdef H5
    if (tabWarmupScheduled || typeof window === 'undefined') return;
    tabWarmupScheduled = true;
    const load = () => {
        void Promise.allSettled([...Array.from(rootTabs, (url) => loadTabCode(url))]);
    };
    if ('requestIdleCallback' in window) window.requestIdleCallback(load, { timeout: 2000 });
    else setTimeout(load, 500);
    // #endif
}
