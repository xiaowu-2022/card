import { Head, Link } from '@inertiajs/react';
import { t, dateTime, useAdminTranslation } from '@/i18n/admin';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { MessageSquare, ChevronRight } from 'lucide-react';
import { useSupportPolling } from '@/components/support/useSupportPolling';

type Inbox = {
    page: number;
    hasMore: boolean;
    items: { id: string; accountId: string; awaitingReply: boolean; updatedAt: string }[];
};
export default function SupportInbox({ inbox }: { inbox: Inbox }) {
    useAdminTranslation();
    const disconnected = useSupportPolling('inbox');
    return (
        <TenantAdminLayout>
            <Head title={t('Support messages')} />
            <div className="space-y-6">
                <PageHeader
                    title={t('Support messages')}
                    description={t('Receive customer messages and reply with text or images.')}
                />
                {disconnected && (
                    <p role="status" className="text-sm text-danger">
                        {t(
                            'Connection interrupted. Reconnecting… Your draft is saved on this page.',
                        )}
                    </p>
                )}
                <div className="divide-y rounded-xl border bg-surface">
                    {inbox.items.length === 0 && (
                        <p className="p-10 text-center text-sm text-muted-foreground">
                            {t('No customer conversations yet.')}
                        </p>
                    )}
                    {inbox.items.map((item) => (
                        <Link
                            href={`/admin/support/${item.id}`}
                            key={item.id}
                            className="flex min-w-0 items-center gap-3 p-4 hover:bg-muted sm:p-5"
                        >
                            <MessageSquare className="size-5 shrink-0 text-primary" />
                            <div className="min-w-0 flex-1">
                                <p className="truncate text-sm font-semibold">
                                    {t('Account ID')}: {item.accountId}
                                </p>
                                <time
                                    className="text-xs text-muted-foreground"
                                    dateTime={item.updatedAt}
                                >
                                    {dateTime(item.updatedAt)}
                                </time>
                            </div>
                            <span
                                className={`rounded-full px-2 py-1 text-xs ${item.awaitingReply ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-800'}`}
                            >
                                {item.awaitingReply ? t('Awaiting reply') : t('Replied')}
                            </span>
                            <ChevronRight className="size-4 shrink-0" />
                        </Link>
                    ))}
                </div>
                {(inbox.page > 1 || inbox.hasMore) && (
                    <div className="flex gap-3">
                        {inbox.page > 1 && (
                            <Button asChild variant="secondary">
                                <Link href={`/admin/support?page=${inbox.page - 1}`}>
                                    {t('Previous page')}
                                </Link>
                            </Button>
                        )}
                        {inbox.hasMore && (
                            <Button asChild variant="secondary">
                                <Link href={`/admin/support?page=${inbox.page + 1}`}>
                                    {t('Next page')}
                                </Link>
                            </Button>
                        )}
                    </div>
                )}
            </div>
        </TenantAdminLayout>
    );
}
