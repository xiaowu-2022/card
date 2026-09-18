import { List, ArrowDownToLine, ArrowUpFromLine } from 'lucide-react';
import { Head, Link } from '@inertiajs/react';
import { t, useClientTranslation, dateTime } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { exactAmount as amount } from '@/lib/exact-amount';

type Asset = { asset: string; principal: string; net: string };
type NextInterest = {
    dueAt: string;
    overdue: boolean;
    amounts: { asset: string; amount: string }[];
};
const colors = ['#169b75', '#2876de', '#7561da', '#f58a20'];
export default function WealthOverview({
    assets,
    principalEstimate,
    nextInterest,
}: {
    assets: Asset[];
    principalEstimate: string | null;
    nextInterest: NextInterest | null;
}) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Wealth management')} />
            <div className="space-y-5">
                <UserPageHeader title={t('Wealth management')} backHref="/dashboard" />
                <section className="space-y-4 rounded-3xl bg-gradient-to-br from-emerald-100 to-lime-50 p-5 text-slate-900">
                    <div>
                        <h2 className="text-sm text-slate-600">{t('Deposited amount')}</h2>
                        <p className="mt-2 break-all text-3xl font-semibold tracking-tight tabular-nums">
                            {principalEstimate === null
                                ? t('Valuation unavailable')
                                : `≈ ${amount(principalEstimate)} USDT`}
                        </p>
                    </div>
                    <div className="space-y-2 border-t border-emerald-900/10 pt-4">
                        <h2 className="text-xs text-slate-600">
                            {t('Next expected interest payment')}
                        </h2>
                        {nextInterest ? (
                            <>
                                <p className="text-sm font-medium">
                                    {dateTime(nextInterest.dueAt)}
                                </p>
                                {nextInterest.amounts.map((a) => (
                                    <p
                                        key={a.asset}
                                        className="break-all text-lg font-semibold tabular-nums"
                                    >
                                        {amount(a.amount)} {a.asset}
                                    </p>
                                ))}
                                {nextInterest.overdue && (
                                    <p className="text-xs text-slate-600">
                                        {t('Interest settlement pending')}
                                    </p>
                                )}
                            </>
                        ) : (
                            <p className="text-sm">{t('No upcoming interest payment')}</p>
                        )}
                    </div>
                </section>
                <section>
                    <h2 className="mb-3 font-semibold">{t('All wealth wallets')}</h2>
                    <div className="grid grid-cols-2 gap-3">
                        {assets.map((a, i) => (
                            <div
                                key={a.asset}
                                className="min-w-0 rounded-2xl bg-surface p-4 transition-shadow hover:shadow-md focus-visible:outline-2 focus-visible:outline-emerald-600"
                            >
                                <h3 className="flex items-center gap-2 font-semibold">
                                    <span
                                        className="h-2 w-2 rounded-full"
                                        style={{ background: colors[i] }}
                                    />
                                    {a.asset}
                                </h3>
                                <dl className="mt-4 space-y-3">
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            {t('Wealth principal')}
                                        </dt>
                                        <dd className="mt-1 break-all text-lg font-semibold tabular-nums">
                                            {amount(a.principal)}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-muted-foreground">
                                            {t('Cumulative net earnings')}
                                        </dt>
                                        <dd className="mt-1 break-all text-sm font-medium tabular-nums">
                                            {amount(a.net)}
                                        </dd>
                                    </div>
                                </dl>
                                <div className="mt-4 grid grid-cols-3 gap-1 border-t pt-2">
                                    {(
                                        [
                                            ['details', 'Wealth details action', List],
                                            ['deposit', 'Wealth deposit action', ArrowDownToLine],
                                            ['withdraw', 'Wealth withdraw action', ArrowUpFromLine],
                                        ] as const
                                    ).map(([view, label, Icon]) => (
                                        <Link
                                            key={view}
                                            href={`/wealth/assets/${a.asset}?view=${view}`}
                                            className="flex min-h-14 min-w-0 flex-col items-center justify-center gap-1.5 rounded-lg px-0.5 py-2 text-center text-xs hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            <Icon className="size-4 shrink-0" aria-hidden="true" />
                                            <span className="w-full break-words">{t(label)}</span>
                                        </Link>
                                    ))}
                                </div>
                            </div>
                        ))}
                    </div>
                </section>
                <p className="px-1 text-xs leading-5 text-muted-foreground">
                    {t(
                        'Interest is automatically paid to your balance every month. Paid interest is recovered on early withdrawal.',
                    )}
                </p>
            </div>
        </UserLayout>
    );
}
