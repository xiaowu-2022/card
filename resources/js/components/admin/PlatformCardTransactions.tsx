import { useEffect, useState } from 'react';
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
    transactionStates,
    transactionTitles,
    type CardTransactionPage,
} from '@/lib/card-transactions';
import { exactAmount } from '@/lib/exact-amount';

type Card = {
    id: string;
    tenantId: string;
    companyName: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
};
type Page = CardTransactionPage & { timezone: string };

export function PlatformCardTransactions({ card, onClose }: { card: Card; onClose: () => void }) {
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
                                                {exactAmount(item.amount)} {item.currency}
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
