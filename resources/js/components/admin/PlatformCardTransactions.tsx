import { useEffect, useRef, useState } from 'react';
import { t, useAdminTranslation } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { StatusBadge } from '@/components/shared/StatusBadge';
import {
    transactionPage,
    transactionMoney,
    transactionStates,
    transactionTitles,
    type CardTransactionPage,
} from '@/lib/card-transactions';

type Card = {
    id: string;
    tenantId: string;
    companyName: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
};
type Page = CardTransactionPage & { timezone: string };

export function PlatformCardTransactions({
    card,
    canSync,
    onClose,
}: {
    card: Card;
    canSync: boolean;
    onClose: () => void;
}) {
    const { i18n } = useAdminTranslation();
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [data, setData] = useState<Page | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

    const [syncing, setSyncing] = useState(false);
    const [syncMessage, setSyncMessage] = useState('');
    const [syncDetails, setSyncDetails] = useState('');
    const [nextSyncPage, setNextSyncPage] = useState<number | null>(null);
    const syncRequest = useRef<AbortController | null>(null);
    useEffect(() => () => syncRequest.current?.abort(), []);

    const sync = async (providerPage: number) => {
        if (syncRequest.current) return;
        const controller = new AbortController();
        syncRequest.current = controller;
        setSyncing(true);
        setSyncMessage('');
        setSyncDetails('');
        let failureMessage = 'Card transactions could not be updated. Please try again later.';
        try {
            const xsrf = document.cookie
                .split('; ')
                .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
                ?.slice(11);
            const token = xsrf
                ? decodeURIComponent(xsrf)
                : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
            if (!token) {
                failureMessage = 'Session expired. Refresh the page and sign in again.';
                throw new Error('CSRF token unavailable');
            }
            const response = await fetch(
                `/platform/tenants/${card.tenantId}/cards/${card.id}/transactions/sync`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    cache: 'no-store',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        [xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN']: token,
                    },
                    body: JSON.stringify({ page: providerPage }),
                    signal: AbortSignal.any([controller.signal, AbortSignal.timeout(60000)]),
                },
            );
            const requestId = response.headers.get('X-Request-ID');
            if (!controller.signal.aborted)
                setSyncDetails(
                    `HTTP ${response.status}${requestId && /^[a-f0-9-]{36}$/i.test(requestId) ? ` · ${requestId}` : ''}`,
                );
            if (!response.ok || response.redirected) {
                failureMessage =
                    response.redirected || [401, 419].includes(response.status)
                        ? 'Session expired. Refresh the page and sign in again.'
                        : response.status === 403
                          ? 'You do not have permission to sync transactions.'
                          : [404, 405].includes(response.status)
                            ? 'Sync endpoint or card unavailable. Check deployment and refresh route cache.'
                            : response.status === 429
                              ? 'Too many sync requests. Wait one minute and retry.'
                              : response.status === 503
                                ? 'Provider sync unavailable. Check the server logs using the request ID.'
                                : 'Card transactions could not be updated. Please try again later.';
                throw new Error('Transaction sync failed');
            }
            failureMessage = 'Invalid sync response. Check deployment and server logs.';
            const result: unknown = await response.json();
            if (
                !result ||
                typeof result !== 'object' ||
                !('page' in result) ||
                result.page !== providerPage ||
                !('hasMore' in result) ||
                typeof result.hasMore !== 'boolean'
            )
                throw new Error('Invalid sync response');
            if (controller.signal.aborted) return;
            setSyncDetails('');
            setNextSyncPage(result.hasMore && providerPage < 100000 ? providerPage + 1 : null);
            setSyncMessage(
                result.hasMore
                    ? 'Page synced. You can sync the next page.'
                    : 'Sync completed. No more provider records.',
            );
            setPage(1);
            setAttempt((value) => value + 1);
        } catch (error) {
            if (!controller.signal.aborted) {
                if (error instanceof DOMException && error.name === 'TimeoutError')
                    failureMessage = 'Sync timed out. Check recorded transactions before retrying.';
                else if (error instanceof TypeError)
                    failureMessage = 'Network request failed. Check your connection and retry.';
                setSyncMessage(failureMessage);
            }
        } finally {
            syncRequest.current = null;
            if (!controller.signal.aborted) setSyncing(false);
        }
    };

    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setFailed(false);
        setData(null);
        void (async () => {
            try {
                const response = await fetch(
                    `/platform/tenants/${card.tenantId}/cards/${card.id}/transactions?page=${page}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        cache: 'no-store',
                        signal: AbortSignal.any([controller.signal, AbortSignal.timeout(15000)]),
                    },
                );
                if (!response.ok) throw new Error('Transaction read failed');
                const value: unknown = await response.json();
                const parsed = transactionPage(value, card.id, page);
                if (
                    !value ||
                    typeof value !== 'object' ||
                    !('timezone' in value) ||
                    typeof value.timezone !== 'string' ||
                    !value.timezone
                )
                    throw new Error('Missing timezone');
                new Intl.DateTimeFormat('en', { timeZone: value.timezone });
                if (!controller.signal.aborted) setData({ ...parsed, timezone: value.timezone });
            } catch {
                if (!controller.signal.aborted) setFailed(true);
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        })();
        return () => controller.abort();
    }, [card.id, card.tenantId, page, attempt]);

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                className="flex max-h-[85dvh] max-w-4xl flex-col"
                closeLabel={t('Close')}
            >
                <DialogHeader className="shrink-0 pr-10">
                    <DialogTitle>{t('Card transactions')}</DialogTitle>
                    <DialogDescription className="break-words">
                        {card.companyName} · {card.userEmail} · {card.productName} ·{' '}
                        {card.maskedPan}
                    </DialogDescription>
                    <p className="text-xs text-muted-foreground">
                        {t('Only recorded card transactions are shown.')}
                        {data ? ` · ${t('Timezone')}: ${data.timezone}` : ''}
                    </p>
                </DialogHeader>
                {canSync && (
                    <div className="shrink-0 space-y-2">
                        <div className="flex gap-2">
                            <Button disabled={syncing} onClick={() => void sync(1)}>
                                {t(syncing ? 'Syncing transactions…' : 'Sync latest transactions')}
                            </Button>
                            {nextSyncPage !== null && (
                                <Button
                                    variant="secondary"
                                    disabled={syncing}
                                    onClick={() => void sync(nextSyncPage)}
                                >
                                    {t('Sync next page')} · {nextSyncPage}
                                </Button>
                            )}
                        </div>
                        <p className="text-xs text-muted-foreground" role="status">
                            {t(syncMessage || 'Each click syncs up to 20 provider records.')}
                            {syncDetails && (
                                <span className="block select-text">{syncDetails}</span>
                            )}
                        </p>
                    </div>
                )}
                <div className="min-h-24 min-w-0 flex-1 overflow-auto" aria-busy={loading}>
                    {loading ? (
                        <p role="status" className="py-8 text-center text-sm">
                            {t('Loading')}
                        </p>
                    ) : failed ? (
                        <div role="alert" className="space-y-3 py-6 text-center">
                            <p className="text-sm">
                                {t('Card transactions could not be loaded. Please try again.')}
                            </p>
                            <Button
                                variant="secondary"
                                onClick={() => setAttempt((value) => value + 1)}
                            >
                                {t('Retry')}
                            </Button>
                        </div>
                    ) : data?.items.length === 0 ? (
                        <p role="status" className="py-8 text-center text-sm text-muted-foreground">
                            {t('No recorded card transactions.')}
                        </p>
                    ) : (
                        data && (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        {[
                                            'Type',
                                            'Merchant',
                                            'Amount',
                                            'Transaction fee',
                                            'Fee refund',
                                            'Status',
                                            'Time',
                                            'Operator',
                                            'Consumption reference or note',
                                        ].map((label) => (
                                            <TableHead key={label}>{t(label)}</TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {data.items.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell className="whitespace-nowrap">
                                                {t(
                                                    transactionTitles[item.type] ??
                                                        'Card transaction',
                                                )}
                                            </TableCell>
                                            <TableCell className="min-w-32 max-w-64 break-words">
                                                {item.merchant || '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap font-medium tabular-nums">
                                                {transactionMoney(item.amount, item.currency)}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {item.feeAmount != null && item.feeCurrency
                                                    ? transactionMoney(
                                                          item.feeAmount,
                                                          item.feeCurrency,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap tabular-nums">
                                                {item.feeReturnAmount != null &&
                                                item.feeReturnCurrency
                                                    ? transactionMoney(
                                                          item.feeReturnAmount,
                                                          item.feeReturnCurrency,
                                                      )
                                                    : '—'}
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        item.state === 'declined'
                                                            ? 'DANGER'
                                                            : item.state === 'completed'
                                                              ? 'SUCCESS'
                                                              : 'INFO'
                                                    }
                                                    label={t(
                                                        transactionStates[item.state] ?? 'Pending',
                                                    )}
                                                />
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                <div>
                                                    {new Intl.DateTimeFormat(i18n.language, {
                                                        timeZone: data.timezone,
                                                        year: 'numeric',
                                                        month: '2-digit',
                                                        day: '2-digit',
                                                        hour: '2-digit',
                                                        minute: '2-digit',
                                                        second: '2-digit',
                                                        hour12: false,
                                                    }).format(new Date(item.displayAt))}
                                                </div>
                                                <div className="text-xs text-muted-foreground">
                                                    {t(
                                                        item.timeKind === 'completed'
                                                            ? 'Completion time'
                                                            : 'Recorded time',
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>{item.operator ?? '—'}</TableCell>
                                            <TableCell>{item.note ?? '—'}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )
                    )}
                </div>
                <div className="flex shrink-0 items-center justify-between gap-3 border-t pt-4">
                    <Button
                        variant="secondary"
                        disabled={loading || page <= 1}
                        onClick={() => setPage((value) => value - 1)}
                    >
                        {t('Previous')}
                    </Button>
                    <span className="text-sm tabular-nums">{page}</span>
                    <Button
                        variant="secondary"
                        disabled={loading || failed || !data?.hasMore}
                        onClick={() => setPage((value) => value + 1)}
                    >
                        {t('Next')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
