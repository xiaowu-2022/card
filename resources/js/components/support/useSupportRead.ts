import { useEffect } from 'react';

// Acknowledge only the sequence returned to this visible page, never future replies.
export function useSupportRead(through: number, enabled: boolean) {
    useEffect(() => {
        if (!enabled || through < 1) return;
        let done = false;
        let busy = false;
        const controller = new AbortController();
        const acknowledge = async () => {
            if (done || busy || document.visibilityState !== 'visible') return;
            busy = true;
            try {
                const csrf = decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((c) => c.startsWith('XSRF-TOKEN='))
                        ?.slice(11) ?? '',
                );
                const response = await fetch('/support/read', {
                    method: 'POST',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ through }),
                    signal: controller.signal,
                });
                if (response.ok && !response.redirected) {
                    done = true;
                    window.dispatchEvent(new Event('inbox-read'));
                }
            } catch {
                /* Retry while the conversation remains visible. */
            } finally {
                busy = false;
            }
        };
        const refresh = () => {
            void acknowledge();
        };
        refresh();
        const timer = window.setInterval(refresh, 5000);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            done = true;
            controller.abort();
            clearInterval(timer);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [through, enabled]);
}
