import { Head, router } from '@inertiajs/react';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { Button } from '@/components/ui/button';
import type { MoneyAmount } from '@/types/global';

type History = {
    data: {
        id: string;
        amount: MoneyAmount;
        asset: string;
        state: 'pending' | 'completed' | 'cancelled';
        requestedAt: string;
    }[];
    currentPage: number;
    lastPage: number;
};
const labels = {
    pending: 'Refund checks pending',
    completed: 'Security deposit refunded',
    cancelled: 'Refund request cancelled',
};

export default function SecurityDepositHistory({ history }: { history: History }) {
    useClientTranslation();
    const navigate = (page: number) =>
        router.get(
            '/security-deposit/history',
            { page },
            { only: ['history'], preserveState: true, preserveScroll: true },
        );
    return (
        <UserLayout>
            <Head title={t('Security deposit history')} />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader
                    title={t('Security deposit history')}
                    backHref="/security-deposit"
                />
                {history.data.length === 0 ? (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        {t('No security deposit history yet.')}
                    </p>
                ) : (
                    <ul className="divide-y">
                        {history.data.map((row) => (
                            <li
                                key={row.id}
                                className="flex items-start justify-between gap-4 py-4"
                            >
                                <div className="min-w-0 space-y-2">
                                    <p className="text-sm font-medium">{t(labels[row.state])}</p>
                                    <time
                                        dateTime={row.requestedAt}
                                        className="block text-xs text-muted-foreground"
                                    >
                                        {dateTime(row.requestedAt)}
                                    </time>
                                </div>
                                <span className="shrink-0 text-sm font-semibold tabular-nums">
                                    <MoneyDisplay amount={row.amount} asset={row.asset} compact />
                                </span>
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
            </div>
        </UserLayout>
    );
}
