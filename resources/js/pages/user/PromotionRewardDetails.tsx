import { promotionLevel } from '@/lib/paid-promotion';
import { Head, Link } from '@inertiajs/react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
export default function PromotionRewardDetails({
    details: d,
}: {
    details: {
        kind: string;
        rank: number;
        page: number;
        hasMore: boolean;
        items: {
            id: string;
            direct: boolean;
            rate: string;
            amount: string;
            sourceAmount: string;
            occurredAt: string;
            accountId: string;
        }[];
    };
}) {
    useClientTranslation();
    const url = (page: number) => `/promotion/rewards?kind=${d.kind}&rank=${d.rank}&page=${page}`;
    return (
        <UserLayout>
            <Head title={t('Commission details')} />
            <UserPageHeader
                title={t(
                    d.kind === 'ANNUAL'
                        ? 'Annual fee commission details'
                        : 'Activation commission details',
                )}
                backHref="/promotion"
            />
            <p className="mb-4">{promotionLevel(d.rank)}</p>
            {d.items.map((r) => (
                <div key={r.id} className="space-y-2 border-b py-4 text-sm">
                    <div className="flex justify-between gap-3">
                        <span>
                            {r.accountId} · {t(r.direct ? 'Direct' : 'Indirect')}
                        </span>
                        <strong>{exactAmount(r.amount)} USDT</strong>
                    </div>
                    <p>
                        {t('Source amount')}: {exactAmount(r.sourceAmount)} {'USDT'} ·{' '}
                        {t(d.kind === 'ANNUAL' ? 'Reward rate' : 'Reward difference')}:{' '}
                        {exactAmount(r.rate)} {d.kind === 'ANNUAL' ? '%' : 'USDT'}
                    </p>
                    <p className="text-xs text-muted-foreground">{dateTime(r.occurredAt)}</p>
                </div>
            ))}
            {!d.items.length && <p>{t('No activity yet')}</p>}
            <div className="mt-5 flex justify-between">
                {d.page > 1 && <Link href={url(d.page - 1)}>{t('Previous')}</Link>}
                {d.hasMore && <Link href={url(d.page + 1)}>{t('Next')}</Link>}
            </div>
        </UserLayout>
    );
}
