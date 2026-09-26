import { createContext, useContext, useEffect, useState, type ReactNode } from 'react';
import { Link, usePage } from '@inertiajs/react';
import { Bell } from 'lucide-react';
import { t } from '@/i18n';
import type { SharedProps } from '@/types/global';

const UnreadContext = createContext({ messages: 0, support: 0 });
export function UnreadMessagesProvider({ children }: { children: ReactNode }) {
    const { props } = usePage<SharedProps>();
    const scope = `${props.tenant?.id ?? ''}:${props.auth.user?.id ?? ''}`;
    const [state, setState] = useState({
        scope,
        messages: props.unreadMessages ?? 0,
        support: props.unreadSupport ?? 0,
    });
    useEffect(() => {
        setState({ scope, messages: props.unreadMessages ?? 0, support: props.unreadSupport ?? 0 });
    }, [scope, props.unreadMessages, props.unreadSupport]);
    useEffect(() => {
        if (!props.auth.user) return;
        let active = true;
        let controller: AbortController | null = null;
        const update = async () => {
            if (document.visibilityState !== 'visible' || controller) return;
            controller = new AbortController();
            try {
                const response = await fetch('/messages/unread-count', {
                    headers: { Accept: 'application/json' },
                    signal: controller.signal,
                });
                if (!response.ok || response.redirected) return;
                const data = (await response.json()) as { count: number; supportCount?: number };
                if (active && Number.isSafeInteger(data.count) && data.count >= 0)
                    setState({
                        scope,
                        messages: data.count,
                        support:
                            Number.isSafeInteger(data.supportCount) && data.supportCount! >= 0
                                ? data.supportCount!
                                : 0,
                    });
            } catch {
                /* Keep the last confirmed count; retry on the next visible tick. */
            } finally {
                controller = null;
            }
        };
        const refresh = () => {
            void update();
        };
        const timer = window.setInterval(refresh, 30000);
        window.addEventListener('focus', refresh);
        window.addEventListener('inbox-read', refresh);
        document.addEventListener('visibilitychange', refresh);
        return () => {
            active = false;
            controller?.abort();
            clearInterval(timer);
            window.removeEventListener('focus', refresh);
            window.removeEventListener('inbox-read', refresh);
            document.removeEventListener('visibilitychange', refresh);
        };
    }, [scope, props.auth.user]);
    return (
        <UnreadContext.Provider value={state.scope === scope ? state : { messages: 0, support: 0 }}>
            {children}
        </UnreadContext.Provider>
    );
}
export function UnreadBadge({ kind = 'messages' }: { kind?: 'messages' | 'support' | 'total' }) {
    const counts = useContext(UnreadContext);
    const count = kind === 'total' ? counts.messages + counts.support : counts[kind];
    return count > 0 ? (
        <span
            className="absolute -right-1 -top-1 min-w-4 rounded-full bg-red-600 px-1 text-center text-[10px] leading-4 text-white"
            aria-label={t('{{count}} unread messages', { count })}
        >
            {count > 99 ? '99+' : count}
        </span>
    ) : null;
}
export function MessageBell() {
    return (
        <Link
            href="/messages"
            className="relative inline-flex size-11 shrink-0 items-center justify-center rounded-full hover:bg-muted"
            aria-label={t('Messages')}
        >
            <span className="relative">
                <Bell className="size-5" aria-hidden="true" />
                <UnreadBadge />
            </span>
        </Link>
    );
}
