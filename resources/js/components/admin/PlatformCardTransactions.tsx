import { adminAssetLabel } from '@/lib/admin-asset-label';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { DetailDrawerContent } from '@/components/admin/DetailDrawer';
import { useEffect, useState } from 'react';
import { t, useAdminTranslation } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Dialog, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
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
    onSync,
    refreshKey = 0,
    onClose,
}: {
    card: Card;
    onSync?: () => void;
    refreshKey?: number;
    onClose: () => void;
}) {
    const { i18n } = useAdminTranslation();
    const [page, setPage] = useState(1);
    const [attempt, setAttempt] = useState(0);
    const [data, setData] = useState<Page | null>(null);
    const [loading, setLoading] = useState(true);
    const [failed, setFailed] = useState(false);

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
    }, [card.id, card.tenantId, page, attempt, refreshKey]);

    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DetailDrawerContent closeLabel={t('Close')}>
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
                {onSync && (
                    <div className="shrink-0 pb-3">
                        <Button onClick={onSync}>{t('Sync this card')}</Button>
                        <p className="mt-2 text-xs text-muted-foreground">
                            {t(
                                'Choose dates once. This browser automatically processes all pages through the server. Keep this page open.',
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
                            <OperationFeedback className="text-sm">
                                {t('Card transactions could not be loaded. Please try again.')}
                            </OperationFeedback>
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
                                                {adminAssetLabel(
                                                    transactionMoney(item.amount, item.currency),
                                                )}
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
            </DetailDrawerContent>
        </Dialog>
    );
}
