import { onBeforeUnmount } from 'vue';
import { onHide } from '@dcloudio/uni-app';
// A cached uni-app page is not unmounted when covered by another page.
export function useSensitiveScreen(clear: () => void) {
    onHide(clear);
    onBeforeUnmount(() => {
        clear();
        // #ifdef H5
        document.removeEventListener('visibilitychange', visibility);
        // #endif
    });
    function visibility() {
        // #ifdef H5
        if (document.hidden) clear();
        // #endif
    }
    // #ifdef H5
    document.addEventListener('visibilitychange', visibility);
    // #endif
}
