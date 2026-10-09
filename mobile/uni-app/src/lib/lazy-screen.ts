import { defineAsyncComponent, defineComponent, h, type Component } from 'vue';
import PageSkeleton from '../components/PageSkeleton.vue';

const LoadingScreen = defineComponent(() => () => h(PageSkeleton, { full: true }));

// Keep the route shell small. A slow chunk download retains immediate back navigation;
// failures bubble to the screen's existing error boundary and retry action.
export function lazyScreen(loader: () => Promise<{ default: Component }>) {
    return defineAsyncComponent({
        loader,
        loadingComponent: LoadingScreen,
        delay: 0,
        timeout: 20000,
    });
}
