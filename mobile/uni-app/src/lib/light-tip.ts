import { ref, onBeforeUnmount } from 'vue';
import { onHide } from '@dcloudio/uni-app';
export function useLightTip() {
    const message = ref('');
    let timer: ReturnType<typeof setTimeout> | undefined;
    function clear() { clearTimeout(timer); message.value = ''; }
    function show(text: string, duration = 1800) {
        clear();
        message.value = text;
        timer = setTimeout(clear, duration);
    }
    onHide(clear);
    onBeforeUnmount(clear);
    return { message, show, clear };
}
