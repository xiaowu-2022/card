import { ref } from 'vue';
import { onShow } from '@dcloudio/uni-app';
import { ApiError } from './api';
import { clearSession, requireUser } from './session';
export function useScreen(load: () => Promise<void>) {
    const loading = ref(true);
    const failed = ref(false);
    async function refresh() {
        loading.value = true; failed.value = false;
        try { if (await requireUser()) await load(); }
        catch (error) {
            failed.value = true;
            if (error instanceof ApiError && error.status === 401) { clearSession(); uni.reLaunch({ url: '/pages/login/index' }); }
        } finally { loading.value = false; }
    }
    onShow(() => { void refresh(); });
    return { loading, failed, refresh };
}
