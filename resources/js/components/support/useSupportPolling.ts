import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { subscribeSupportRealtime, supportRealtimeConnected } from '@/lib/supportRealtime';

export function useSupportPolling(prop: 'chat' | 'inbox' | 'workspace', enabled = true) {
    const [disconnected, setDisconnected] = useState(false);
    useEffect(() => {
        if (!enabled) return;
        let busy = false;
        let pending = false,
            stopped = false;
        const refresh = () => {
            if (stopped) return;
            if (busy) {
                pending = true;
                return;
            }
            if (document.visibilityState !== 'visible') return;
            busy = true;
            router.reload({
                only: prop === 'workspace' ? ['chat', 'inbox'] : [prop],
                onSuccess: () => setDisconnected(false),
                onError: () => setDisconnected(true),
                onNetworkError: () => {
                    setDisconnected(true);
                    return false;
                },
                onHttpException: () => {
                    setDisconnected(true);
                    return false;
                },
                onFinish: () => {
                    busy = false;
                    if (pending) {
                        pending = false;
                        refresh();
                    }
                },
            });
        };
        const unsubscribe = subscribeSupportRealtime(refresh);
        const foreground = () => {
            if (!document.hidden) refresh();
        };
        document.addEventListener('visibilitychange', foreground);
        const timer = window.setInterval(() => {
            if (!supportRealtimeConnected()) refresh();
        }, 5000);
        return () => {
            stopped = true;
            unsubscribe();
            document.removeEventListener('visibilitychange', foreground);
            window.clearInterval(timer);
        };
    }, [prop, enabled]);
    return disconnected;
}
