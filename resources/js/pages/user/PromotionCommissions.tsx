import { promotionMoney as systemMoney } from '@/lib/paid-promotion';
import { Head, router } from '@inertiajs/react';
import { ChevronDown, ReceiptText } from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { useRef } from 'react';
import { PromotionDateFilter } from '@/components/user/PromotionDateFilter';
import { t, dateTime, useClientTranslation } from '@/i18n';
import '../../../css/promotion.css';

type History = {
    date: string | null;
    timezone: string;
    page: number;
    hasMore: boolean;
    items: {
        id: string;
        kind: 'earned' | 'annual' | 'transferred';
        amount: string;
        asset: string;
        sourceAccountId: string | null;
        occurredAt: string;
    }[];
};

export default function PromotionCommissions({ history }: { history: History }) {
    useClientTranslation();
    const resultsRef = useRef<HTMLDivElement>(null);
    const visit = (page: number, date = history.date) =>
        router.get(
            '/promotion/commissions',
            { ...(date ? { date } : {}), page },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    requestAnimationFrame(() =>
                        resultsRef.current?.scrollIntoView({ block: 'start' }),
                    ),
            },
        );
    return (
        <UserLayout>
            <Head title={t('Commission details')} />
            <div className="promotion-page promotion-commissions-page">
                <div className="promotion-commissions-header">
                    <UserPageHeader
                        title={t('Commission details')}
                        backHref="/promotion/invitations"
                    />
                    <p className="promotion-section-note">
                        {t('Only your commission records are shown.')}
                    </p>
                    <PromotionDateFilter
                        id="commission-date"
                        date={history.date}
                        allowAll
                        onChange={(date) => visit(1, date)}
                    />
                    <details className="promotion-explanation">
                        <summary>
                            {t('Statistics notes')}
                            <ChevronDown aria-hidden="true" />
                        </summary>
                        <p>
                            {t(
                                'Your commission income and transfers to wallet balance only. Team rewards belong in team overview.',
                            )}
                        </p>
                        <p>{t('Dates and times follow the company timezone.')}</p>
                    </details>
                </div>
                <div ref={resultsRef} className="promotion-results">
                    {history.items.length === 0 && (
                        <div className="promotion-empty">
                            <ReceiptText aria-hidden="true" />
                            <p>{t('No commission records for this period.')}</p>
                        </div>
                    )}
                    <ul className="promotion-activity">
                        {history.items.map((row) => (
                            <li key={row.id}>
                                <div className="promotion-activity-description">
                                    <div className="promotion-activity-heading">
                                        <p className="font-medium">
                                            {row.kind !== 'transferred'
                                                ? t(
                                                      row.kind === 'annual'
                                                          ? 'My annual fee commission'
                                                          : 'Commission earned',
                                                  )
                                                : t('Commission transferred to balance')}
                                        </p>
                                        <span
                                            className={`promotion-activity-amount ${row.kind !== 'transferred' ? 'text-emerald-700' : ''}`}
                                        >
                                            {row.kind !== 'transferred' ? '+' : ''}
                                            {systemMoney(row.amount)}
                                        </span>
                                    </div>
                                    {row.sourceAccountId && (
                                        <p>
                                            {t('Source account')}:{' '}
                                            <span className="font-mono">{row.sourceAccountId}</span>
                                        </p>
                                    )}
                                    <p>{dateTime(row.occurredAt)}</p>
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
                {(history.page > 1 || history.hasMore) && (
                    <div className="promotion-pagination">
                        <Button
                            variant="ghost"
                            disabled={history.page === 1}
                            onClick={() => visit(history.page - 1)}
                        >
                            {t('Previous')}
                        </Button>
                        <Button
                            variant="ghost"
                            disabled={!history.hasMore}
                            onClick={() => visit(history.page + 1)}
                        >
                            {t('Next')}
                        </Button>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
