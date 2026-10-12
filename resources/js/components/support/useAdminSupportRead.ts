import { useEffect } from 'react';
import { router } from '@inertiajs/react';

export function useAdminSupportRead(url: string, through: number, enabled: boolean) {
    useEffect(() => {
        if (!enabled || through < 1) return;
        let done = false,
            busy = false;
        const controller = new AbortController();
        const read = async () => {
            if (done || busy || document.hidden) return;
            busy = true;
            try {
                const csrf = decodeURIComponent(
                    document.cookie
                        .split('; ')
                        .find((c) => c.startsWith('XSRF-TOKEN='))
                        ?.slice(11) ?? '',
                );
                const response = await fetch(`${url}/read`, {
                    method: 'POST',
                    credentials: 'same-origin',
                    signal: controller.signal,
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-XSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ through }),
                });
                if (response.ok && !response.redirected && !controller.signal.aborted) {
                    done = true;
                    window.dispatchEvent(new Event('admin-support-read'));
                    if (url.startsWith('/platform/')) router.reload({ only: ['inbox'] });
                }
            } catch {
                /* Retry only while the delivered thread remains visible. */
            } finally {
                busy = false;
            }
        };
        const refresh = () => {
            void read();
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
    }, [url, through, enabled]);
}
