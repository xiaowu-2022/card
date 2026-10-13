import { nextTick, watch } from 'vue';
import { onHide, onPageScroll, onShow, onUnload } from '@dcloudio/uni-app';
import { consumerSessionScope } from './session';

/** Only navigation positions; no balances, personal records or form drafts. */
export function usePagePosition(key: () => string, ready: () => boolean = () => true) {
    let position = 0,
        visible = false,
        generation = 0;
    let scope = consumerSessionScope();
    // #ifdef H5
    onPageScroll((event) => {
        if (visible) position = event.scrollTop;
    });
    onHide(() => {
        visible = false;
        generation++;
    });
    onUnload(() => {
        visible = false;
        generation++;
    });
    watch(
        consumerSessionScope,
        (value) => {
            if (scope !== value) {
                scope = value;
                position = 0;
                generation++;
            }
        },
        { flush: 'sync' },
    );
    watch(key, () => {
        position = 0;
        generation++;
    });
    onShow(async () => {
        visible = true;
        const run = ++generation,
            saved = position;
        await nextTick();
        if (run === generation && scope && ready())
            uni.pageScrollTo({ scrollTop: saved, duration: 0 });
    });
    // Keep restoration pending until the page has content. Never restore over user scrolling.
    watch(ready, async (value) => {
        if (!value || !visible || !scope) return;
        const run = generation,
            saved = position;
        await nextTick();
        if (run === generation) uni.pageScrollTo({ scrollTop: saved, duration: 0 });
    });
    // #endif
}
