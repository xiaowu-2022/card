import { ArrowDownLeft, ArrowUpRight } from 'lucide-react';
import { Head, Link, router } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserLayout } from '@/layouts/UserLayout';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
export default function AssetHistory({
    selectedAsset,
    balances,
    rows,
}: {
    selectedAsset: string;
    balances: { asset: string; available: string }[];
    rows: {
        data: { asset: string; id: string; amount: string; time: string; kind: string }[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
}) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Funds activity')} />
            <div className="wealth-currency-header mb-5">
                <UserPageHeader
                    title={
                        selectedAsset === 'ALL'
                            ? t('Funds activity')
                            : t('{{asset}} funds account', { asset: selectedAsset })
                    }
                    backHref={selectedAsset === 'ALL' ? '/account' : '/dashboard'}
                    action={
                        <select
                            id="funds-asset"
                            aria-label={t('Select currency')}
                            value={selectedAsset}
                            className="max-w-28 rounded-lg border bg-surface px-2 py-2 text-base"
                            onChange={(e) => router.get('/funds', { asset: e.target.value })}
                        >
                            <option value="ALL">{t('All currencies')}</option>
                            {balances.map((b) => (
                                <option key={b.asset}>{b.asset}</option>
                            ))}
                        </select>
                    }
                />
            </div>
            <section className="mb-5 rounded-2xl bg-gradient-to-br from-emerald-100 to-lime-50 p-5 text-slate-900">
                <h2 className="mb-3 text-sm font-medium">{t('Available funds')}</h2>
                <dl className={selectedAsset === 'ALL' ? 'grid grid-cols-2 gap-4' : ''}>
                    {balances
                        .filter((b) => selectedAsset === 'ALL' || b.asset === selectedAsset)
                        .map((b) => (
                            <div key={b.asset}>
                                <dt className="text-xs text-slate-600">{b.asset}</dt>
                                <dd className="mt-1 break-all text-xl font-semibold tabular-nums">
                                    {exactAmount(b.available)}
                                </dd>
                            </div>
                        ))}
                </dl>
            </section>
            <h2 className="mb-3 font-semibold">{t('Funds movements')}</h2>
            {rows.data.length === 0 && (
                <p className="py-8 text-center text-muted-foreground">{t('No activity yet')}</p>
            )}
            <div className="divide-y rounded-2xl border border-black/5 bg-surface px-4 shadow-sm">
                {rows.data.map((r) => {
                    const debit = r.amount.startsWith('-');
                    const Icon = debit ? ArrowUpRight : ArrowDownLeft;
                    const value = exactAmount(r.amount);
                    return (
                        <div key={r.id} className="flex items-start gap-3 py-4 text-sm">
                            <span
                                className={`mt-1 flex size-9 shrink-0 items-center justify-center rounded-full ${debit ? 'bg-muted text-slate-600' : 'bg-emerald-50 text-emerald-700'}`}
                            >
                                <Icon className="size-4" aria-hidden="true" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                    <p className="font-medium">{t(r.kind)}</p>
                                    <p
                                        className={`min-w-0 break-all font-semibold tabular-nums ${debit ? 'text-foreground' : 'text-emerald-700'}`}
                                    >
                                        {debit || value === '0' ? value : `+${value}`}{' '}
                                        <span className="whitespace-nowrap">{r.asset}</span>
                                    </p>
                                </div>
                                <div className="mt-1 flex flex-wrap justify-between gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                    <p>{dateTime(r.time)}</p>
                                    <p>{t(debit ? 'Wallet debit' : 'Wallet credit')}</p>
                                </div>
                            </div>
                        </div>
                    );
                })}
            </div>
            <div className="mt-5 flex justify-between">
                {rows.prev_page_url && (
                    <Link className="min-h-11 py-3" href={rows.prev_page_url}>
                        {t('Previous')}
                    </Link>
                )}
                {rows.next_page_url && (
                    <Link className="min-h-11 py-3" href={rows.next_page_url}>
                        {t('Next')}
                    </Link>
                )}
            </div>
        </UserLayout>
    );
}
