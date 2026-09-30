import { onBeforeUnmount } from 'vue';
import { onHide } from '@dcloudio/uni-app';
// A cached uni-app page is not unmounted when covered by another page.
export function useSensitiveScreen(clear: () => void, options: { retainOnBackground?: boolean; retainUntilUnmount?: boolean } = {}) {
    onHide(() => {
        if (options.retainUntilUnmount) return;
        // #ifdef H5
        // System photo selection backgrounds H5 without navigating away from the form.
        if (options.retainOnBackground && document.hidden) return;
        // #endif
        clear();
    });
    onBeforeUnmount(() => {
        clear();
        // #ifdef H5
        document.removeEventListener('visibilitychange', visibility);
        // #endif
    });
    function visibility() {
        // #ifdef H5
        if (document.hidden && !options.retainOnBackground && !options.retainUntilUnmount) clear();
        // #endif
    }
    // #ifdef H5
    document.addEventListener('visibilitychange', visibility);
    // #endif
}
