import { ref, watch, onBeforeUnmount } from 'vue';
import { onShow, onHide } from '@dcloudio/uni-app';
import { ApiError } from './api';
import { bootstrap, session, clearSession, requireUser, consumerSessionScope } from './session';
export function useScreen(load: () => Promise<void>, options: { refreshOnShow?: () => boolean; onAccessDenied?: () => void } = {}) {
    const loading = ref(true);
    const failed = ref(false);
    let generation = 0, visible = true, warmReturn = false;
    // #ifdef H5
    onHide(() => { visible = false; warmReturn = typeof document !== 'undefined' && document.hidden; if (!warmReturn) generation++; });
    watch(consumerSessionScope, (value, previous) => { if (previous && value !== previous) { generation++; loading.value = false; failed.value = false; } }, { flush: 'sync' });
    onBeforeUnmount(() => { generation++; });
    // #endif
    let flight: { generation: number; promise: Promise<void> } | null = null;
    function refresh(): Promise<void> {
        if (flight?.generation === generation) return flight.promise;
        const promise = refreshOnce();
        const entry = { generation, promise };
        flight = entry;
        void promise.finally(() => { if (flight === entry) flight = null; });
        return promise;
    }
    async function refreshOnce() {
        const run = ++generation;
        loading.value = true;
        failed.value = false;
        try {
            if (await requireUser()) await load();
        } catch (error) {
            // #ifdef H5
            if (run !== generation) return;
            // #endif
            failed.value = true;
            if (!visible) return;
            if (error instanceof ApiError && [401,403,419].includes(error.status)) options.onAccessDenied?.();
            if (error instanceof ApiError && error.status === 401) {
                try {
                    await bootstrap();
                    if (run !== generation || !visible) return;
                    if (!session.value?.user) {
                        clearSession();
                        uni.reLaunch({ url: '/pages/login/index' });
                    }
                } catch { /* A failed recovery is not proof of logout; show retry. */ }
            }
        } finally {
            // #ifdef H5
            if (run !== generation) return;
            // #endif
            loading.value = false;
        }
    }
    onShow(() => {
        visible = true;
        if (warmReturn) { warmReturn = false; if (!loading.value && !failed.value) return; }
        if (options.refreshOnShow && !options.refreshOnShow()) return;
        void refresh();
    });
    return { loading, failed, refresh };
}
