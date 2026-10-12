import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { t } from '@/i18n/admin';

const UnreadContext = createContext(0);
export function SupportUnreadBadge({ count }: { count?: number }) {
    const globalCount = useContext(UnreadContext);
    const value = count ?? globalCount;
    return value > 0 ? (
        <span
            className="inline-flex min-w-5 shrink-0 items-center justify-center rounded-full bg-red-600 px-1.5 text-xs font-semibold text-white"
            aria-label={`${t('Unread messages')}: ${value}`}
        >
            {value > 99 ? '99+' : value}
        </span>
    ) : null;
}

export function AdminSupportReminder({
    children,
    platform,
}: {
    children: ReactNode;
    platform: boolean;
}) {
    const { auth, tenant } = usePage<SharedProps>().props;
    const actor = auth.admin;
    const allowed =
        actor?.permissions.includes(platform ? 'support.read' : 'support.manage') === true;
    const [count, setCount] = useState(0);
    useEffect(() => {
        setCount(0);
        if (!allowed) return;
        const audio = new Audio('/audio/support-unread.m4a');
        audio.preload = 'auto';
        let freshCount = 0,
            lastFresh = 0,
            lastSpoken = 0,
            pendingNewMessage = false,
            unlocked = false,
            busy = false,
            stopped = false,
            readRevision = 0;
        const controller = new AbortController();
        const remind = () => {
            if (
                document.hidden ||
                !unlocked ||
                freshCount < 1 ||
                Date.now() - lastFresh > 15000 ||
                (!pendingNewMessage && lastSpoken !== 0 && Date.now() - lastSpoken < 60000) ||
                !audio.paused
            )
                return;
            pendingNewMessage = false;
            lastSpoken = Date.now();
            audio.currentTime = 0;
            void audio.play().catch(() => {
                unlocked = false;
            });
        };
        const refresh = async () => {
            if (busy || document.hidden || stopped) return;
            busy = true;
            const revision = readRevision;
            try {
                const response = await fetch(
                    platform ? '/platform/support/unread' : '/admin/support/unread',
                    {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok || response.redirected) throw new Error();
                const data = (await response.json()) as { count: number };
                if (!Number.isSafeInteger(data.count) || data.count < 0) throw new Error();
                if (stopped || revision !== readRevision) return;
                if (data.count > freshCount) pendingNewMessage = true;
                freshCount = data.count;
                lastFresh = Date.now();
                setCount(freshCount);
                if (!freshCount) {
                    audio.pause();
                    lastSpoken = 0;
                    pendingNewMessage = false;
                }
                remind();
            } catch {
                freshCount = 0;
                pendingNewMessage = false;
                audio.pause();
                if (!stopped) setCount(0);
            } finally {
                busy = false;
            }
        };
        const unlock = () => {
            unlocked = true;
            remind();
        };
        const visibility = () => {
            audio.pause();
            if (!document.hidden) void refresh();
        };
        const read = () => {
            readRevision++;
            audio.pause();
            freshCount = 0;
            void refresh();
        };
        document.addEventListener('pointerdown', unlock);
        document.addEventListener('keydown', unlock);
        document.addEventListener('visibilitychange', visibility);
        window.addEventListener('admin-support-read', read);
        void refresh();
        const timer = window.setInterval(() => {
            void refresh();
        }, 5000);
        return () => {
            stopped = true;
            controller.abort();
            audio.pause();
            clearInterval(timer);
            document.removeEventListener('pointerdown', unlock);
            document.removeEventListener('keydown', unlock);
            document.removeEventListener('visibilitychange', visibility);
            window.removeEventListener('admin-support-read', read);
        };
    }, [allowed, actor?.id, tenant?.id, platform]);
    return <UnreadContext.Provider value={allowed ? count : 0}>{children}</UnreadContext.Provider>;
}
