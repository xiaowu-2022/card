import { Link, router } from '@inertiajs/react';
import { t, dateTime } from '@/i18n';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { displayMoney } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import type { MoneyAmount } from '@/types/global';

export type WithdrawalHistoryData = {
    data: {
        id: string;
        amount: MoneyAmount;
        feeAmount: string;
        receiveAmount: string;
        asset: string;
        maskedAddress: string;
        requestedAt: string;
        state: 'pending' | 'processing' | 'confirming' | 'completed' | 'rejected' | 'cancelled';
    }[];
    currentPage: number;
    lastPage: number;
};

const labels = {
    pending: 'Pending review',
    processing: 'Withdrawal approved',
    confirming: 'Confirming transaction',
    completed: 'Withdrawal complete',
    rejected: 'Withdrawal rejected',
    cancelled: 'Withdrawal cancelled',
};

export function WithdrawalHistory({ history }: { history: WithdrawalHistoryData }) {
    const navigate = (page: number) =>
        router.get(
            '/wallet/withdrawals',
            { page },
            {
                only: ['history'],
                preserveState: true,
                preserveScroll: true,
            },
        );
    return (
        <section className="space-y-4" aria-label={t('Withdrawal history')}>
            {history.data.length === 0 ? (
                <p className="py-6 text-center text-sm text-muted-foreground">
                    {t('No withdrawals yet.')}
                </p>
            ) : (
                <ul className="divide-y">
                    {history.data.map((order) => (
                        <li key={order.id}>
                            <Link
                                href={`/wallet/withdrawals/${order.id}`}
                                className="flex justify-between gap-4 py-4"
                            >
                                <div className="min-w-0 space-y-1">
                                    <p className="font-semibold">
                                        <MoneyDisplay amount={order.amount} asset={order.asset} />
                                    </p>
                                    <p className="text-sm text-muted-foreground">
                                        {order.maskedAddress}
                                    </p>
                                    {!['rejected', 'cancelled'].includes(order.state) && (
                                        <p className="text-xs text-muted-foreground">
                                            {t('Withdrawal fee')}: {displayMoney(order.feeAmount)}{' '}
                                            USDT
                                            <br />
                                            {t('Amount to receive')}:{' '}
                                            {displayMoney(order.receiveAmount)} USDT
                                        </p>
                                    )}
                                    <p className="text-xs text-muted-foreground">
                                        {dateTime(order.requestedAt)}
                                    </p>
                                </div>
                                <span className="shrink-0 text-sm">{t(labels[order.state])}</span>
                            </Link>
                        </li>
                    ))}
                </ul>
            )}
            {history.lastPage > 1 && (
                <div className="flex items-center justify-center gap-4">
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={history.currentPage <= 1}
                        onClick={() => navigate(history.currentPage - 1)}
                    >
                        {t('Previous')}
                    </Button>
                    <span className="text-sm tabular-nums">
                        {history.currentPage} / {history.lastPage}
                    </span>
                    <Button
                        variant="secondary"
                        size="sm"
                        disabled={history.currentPage >= history.lastPage}
                        onClick={() => navigate(history.currentPage + 1)}
                    >
                        {t('Next')}
                    </Button>
                </div>
            )}
        </section>
    );
}
