import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { t } from '@/i18n/admin';
import { subscribeSupportRealtime, supportRealtimeConnected } from '@/lib/supportRealtime';

const UnreadContext = createContext(0);

// Inertia keeps this layout mounted across pages, including non-support screens.
export function AdminSupportReminderRoot({ children }: { children: ReactNode }) {
    const { url } = usePage<SharedProps>();
    return (
        <AdminSupportReminder
            platform={/^\/platform(?:\/|$)/.test(url)}
            enabled={/^\/(?:platform|admin)(?:\/|$)/.test(url)}
        >
            {children}
        </AdminSupportReminder>
    );
}

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
    enabled = true,
}: {
    children: ReactNode;
    platform: boolean;
    enabled?: boolean;
}) {
    const { auth, tenant } = usePage<SharedProps>().props;
    const actor = auth.admin;
    const allowed =
        enabled &&
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
            priming = false,
            busy = false,
            stopped = false,
            readRevision = 0,
            pendingRefresh = false,
            lastCheck = 0;
        const controller = new AbortController();
        const remind = () => {
            if (
                stopped ||
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
                pendingNewMessage = freshCount > 0;
                lastSpoken = 0;
            });
        };
        const refresh = async () => {
            if (stopped) return;
            if (busy) {
                pendingRefresh = true;
                return;
            }
            lastCheck = Date.now();
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
                    if (!priming) audio.pause();
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
                if (pendingRefresh && !stopped) {
                    pendingRefresh = false;
                    void refresh();
                }
            }
        };
        const unlock = () => {
            if (unlocked) {
                remind();
                return;
            }
            unlocked = true;
            if (freshCount > 0) {
                remind();
                return;
            }
            // Prime this same audio element during the gesture, before a future hidden-tab arrival.
            priming = true;
            audio.muted = true;
            void audio
                .play()
                .then(() => {
                    priming = false;
                    audio.pause();
                    audio.currentTime = 0;
                    audio.muted = false;
                    if (!stopped) remind();
                })
                .catch(() => {
                    priming = false;
                    audio.muted = false;
                    unlocked = false;
                });
        };
        const visibility = () => {
            // Refresh after either transition, but never stop background sound or mark read.
            void refresh();
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
        const unsubscribe = subscribeSupportRealtime(() => void refresh());
        const timer = window.setInterval(() => {
            if (!supportRealtimeConnected() || Date.now() - lastCheck >= 10000) void refresh();
            remind();
        }, 5000);
        return () => {
            stopped = true;
            unsubscribe();
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
