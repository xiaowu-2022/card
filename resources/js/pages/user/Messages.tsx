import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { messageCopy, type InboxMessage } from '@/lib/inbox';

type MessagePage = {
    data: InboxMessage[];
    prev_page_url: string | null;
    next_page_url: string | null;
};
export default function Messages({ messages, filter }: { messages: MessagePage; filter: string }) {
    useClientTranslation();
    const [busy, setBusy] = useState(false);
    const [failed, setFailed] = useState(false);
    return (
        <UserLayout>
            <Head title={t('Messages')} />
            <UserPageHeader title={t('Messages')} backHref="/account" />
            <div className="mb-4 mt-5 space-y-2">
                <div className="grid grid-cols-4 gap-1">
                    {(['all', 'unread', 'business', 'platform'] as const).map((key) => (
                        <Link
                            key={key}
                            href={`/messages?filter=${key}`}
                            onNetworkError={() => {
                                setFailed(true);
                                return false;
                            }}
                            onHttpException={() => {
                                setFailed(true);
                                return false;
                            }}
                            aria-current={filter === key ? 'page' : undefined}
                            className={`flex min-h-11 items-center justify-center rounded-full px-2 py-2 text-center text-sm ${filter === key ? 'bg-[var(--user-primary-soft)] text-[var(--user-primary)]' : 'text-muted-foreground'}`}
                        >
                            {t(
                                {
                                    all: 'All',
                                    unread: 'Unread',
                                    business: 'Business notifications',
                                    platform: 'Platform notifications',
                                }[key],
                            )}
                        </Link>
                    ))}
                </div>
                <div className="flex justify-end">
                    <Button
                        variant="ghost"
                        disabled={busy}
                        className="ml-auto"
                        onClick={() => {
                            setBusy(true);
                            setFailed(false);
                            router.post(
                                '/messages/read-all',
                                {},
                                {
                                    preserveScroll: true,
                                    onSuccess: () => window.dispatchEvent(new Event('inbox-read')),
                                    onError: () => setFailed(true),
                                    onNetworkError: () => {
                                        setFailed(true);
                                        return false;
                                    },
                                    onHttpException: () => {
                                        setFailed(true);
                                        return false;
                                    },
                                    onFinish: () => setBusy(false),
                                },
                            );
                        }}
                    >
                        {t('Mark all as read')}
                    </Button>
                </div>
            </div>
            {failed && (
                <p role="alert" className="text-sm text-destructive">
                    {t('Could not update messages. Please try again.')}
                </p>
            )}
            {messages.data.length === 0 ? (
                <p className="py-16 text-center text-muted-foreground">{t('No messages yet')}</p>
            ) : (
                <ul className="divide-y">
                    {messages.data.map((message) => {
                        const copy = messageCopy(message);
                        return (
                            <li key={message.id}>
                                <Link href={`/messages/${message.id}`} className="block py-5">
                                    <div className="flex items-start gap-2">
                                        <h2
                                            className={`min-w-0 flex-1 break-words ${message.readAt ? 'font-medium' : 'font-semibold'}`}
                                        >
                                            {copy.title}
                                        </h2>
                                        {!message.readAt && (
                                            <span
                                                className="mt-2 size-2 shrink-0 rounded-full bg-[var(--user-primary)]"
                                                aria-label={t('Unread')}
                                            />
                                        )}
                                    </div>
                                    <p className="mt-2 line-clamp-2 break-words text-sm leading-6 text-muted-foreground">
                                        {copy.body}
                                    </p>
                                    <p className="mt-2 text-xs text-muted-foreground">
                                        {dateTime(message.time)}
                                    </p>
                                </Link>
                            </li>
                        );
                    })}
                </ul>
            )}
            <nav className="mt-5 flex justify-between" aria-label={t('Pagination')}>
                {messages.prev_page_url ? (
                    <Link
                        href={messages.prev_page_url}
                        onNetworkError={() => {
                            setFailed(true);
                            return false;
                        }}
                        className="p-3 text-sm"
                    >
                        {t('Previous')}
                    </Link>
                ) : (
                    <span />
                )}
                {messages.next_page_url && (
                    <Link
                        href={messages.next_page_url}
                        onNetworkError={() => {
                            setFailed(true);
                            return false;
                        }}
                        className="p-3 text-sm"
                    >
                        {t('Next')}
                    </Link>
                )}
            </nav>
        </UserLayout>
    );
}
