import { router } from '@inertiajs/react';
import { useEffect, useState } from 'react';

export function useSupportPolling(prop: 'chat' | 'inbox', enabled = true) {
    const [disconnected, setDisconnected] = useState(false);
    useEffect(() => {
        if (!enabled) return;
        let busy = false;
        const timer = window.setInterval(() => {
            if (busy || document.visibilityState !== 'visible') return;
            busy = true;
            router.reload({
                only: [prop],
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
                },
            });
        }, 5000);
        return () => window.clearInterval(timer);
    }, [prop, enabled]);
    return disconnected;
}
