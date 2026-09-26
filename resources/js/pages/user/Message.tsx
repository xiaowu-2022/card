import { Head, Link, router } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { messageCopy, type InboxMessage } from '@/lib/inbox';

export default function Message({ message }: { message: InboxMessage }) {
    useClientTranslation();
    const [failed, setFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (message.readAt) return;
        router.post(
            `/messages/${message.id}/read`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setFailed(false);
                    window.dispatchEvent(new Event('inbox-read'));
                },
                onError: () => setFailed(true),
                onNetworkError: () => {
                    setFailed(true);
                    return false;
                },
                onHttpException: () => {
                    setFailed(true);
                    return false;
                },
            },
        );
    }, [message.id, message.readAt, retry]);
    const copy = messageCopy(message);
    return (
        <UserLayout>
            <Head title={t('Messages')} />
            <UserPageHeader title={t('Messages')} backHref="/messages" />
            <article className="py-4">
                <p className="text-sm text-muted-foreground">{dateTime(message.time)}</p>
                <h2 className="my-5 break-words text-xl font-semibold">{copy.title}</h2>
                <p className="whitespace-pre-wrap break-words text-base leading-8">{copy.body}</p>
                {message.href && (
                    <Link
                        href={message.href}
                        className="mt-8 inline-block py-3 text-sm text-[var(--user-primary)] underline"
                    >
                        {t('View related record')}
                    </Link>
                )}
            </article>
            {failed && (
                <div role="alert">
                    <p>{t('Could not update messages. Please try again.')}</p>
                    <Button variant="secondary" onClick={() => setRetry((value) => value + 1)}>
                        {t('Retry')}
                    </Button>
                </div>
            )}
        </UserLayout>
    );
}
