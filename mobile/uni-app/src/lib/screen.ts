import { ref, watch, onBeforeUnmount } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import { ApiError } from './api';
import { clearSession, requireUser, consumerSessionScope } from './session';
export function useScreen(load: () => Promise<void>, options: { refreshOnShow?: () => boolean; onAccessDenied?: () => void } = {}) {
    const loading = ref(true);
    const failed = ref(false);
    let generation = 0, visible = true;
    // #ifdef H5
    onHide(() => { visible = false; generation++; });
    watch(consumerSessionScope, (value, previous) => { if (previous && value !== previous) { generation++; loading.value = false; failed.value = false; } }, { flush: 'sync' });
    onBeforeUnmount(() => { generation++; });
    // #endif
    async function refresh() {
        const run = ++generation;
        loading.value = true;
        failed.value = false;
        try {
            if (await requireUser()) await load();
        } catch (error) {
            // #ifdef H5
            if (run !== generation || !visible) return;
            // #endif
            failed.value = true;
            if (error instanceof ApiError && [401,403,419].includes(error.status)) options.onAccessDenied?.();
            if (error instanceof ApiError && error.status === 401) {
                clearSession();
                uni.reLaunch({ url: '/pages/login/index' });
            }
        } finally {
            // #ifdef H5
            if (run !== generation || !visible) return;
            // #endif
            loading.value = false;
        }
    }
    onShow(() => {
        visible = true;
        if (options.refreshOnShow && !options.refreshOnShow()) return;
        void refresh();
    });
    return { loading, failed, refresh };
}
