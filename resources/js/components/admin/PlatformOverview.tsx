import { t, dateTime } from '@/i18n/admin';
import { MoneyDisplay } from '@/components/admin/MoneyDisplay';

type Periods = Record<'today' | 'd3' | 'd7' | 'd15' | 'd30', string | null>;
type Cash = {
    totals: Periods & { total: string | null };
    native: Record<string, Periods & { total: string }>;
};
export type PlatformOverviewData = {
    updatedAt: string;
    timezone: string;
    rateAt: string | null;
    bubble: null;
    partners?: Record<'stock' | 'theoretical' | 'actual' | 'difference', string>;
    reimbursement?: string;
    inflow?: Cash;
    outflow?: Cash;
    cards?: { balance: string | null; today: string; total: string };
    providerBalance?: string;
    assets?: { partner: string | null; ordinary: string | null };
    daily?: Record<string, Periods>;
    alerts?: Record<'remaining' | 'refund10' | 'refund3', string>;
};

const periods = [
    ['today', 'Statistics today'],
    ['d3', '3-day total'],
    ['d7', '7-day total'],
    ['d15', '15-day total'],
    ['d30', '30-day total'],
] as const;
const activity = [
    ['deposits', 'Ordinary member activation deposits'],
    ['annual', 'Annual fee income'],
    ['depositRefunds', 'Ordinary member deposit refunds'],
    ['activation', 'Activation commission'],
    ['rebates', 'Annual fee returns'],
    ['annualCommission', 'Annual commission'],
] as const;

function Amount({ value, asset = 'USDT' }: { value: string | null; asset?: string }) {
    return value === null ? (
        <span className="text-sm font-normal text-muted-foreground">
            {t('Unavailable estimate')}
        </span>
    ) : (
        <MoneyDisplay amount={value} asset={asset} hideSymbol />
    );
}

function Metric({ label, value }: { label: string; value: string | null }) {
    return (
        <div className="min-w-0 rounded-xl border bg-surface p-4">
            <p className="text-sm text-muted-foreground">{t(label)}</p>
            <div className="mt-2 break-all text-xl font-semibold">
                <Amount value={value} />
            </div>
        </div>
    );
}

function CashPanel({
    label,
    cash,
    outflow = false,
}: {
    label: string;
    cash: Cash;
    outflow?: boolean;
}) {
    return (
        <section className="min-w-0 rounded-xl border bg-surface p-4">
            <div className="flex items-center justify-between gap-3">
                <h2 className="font-semibold">{t(label)}</h2>
                <span className="text-xs text-muted-foreground">
                    {outflow ? t('T+1 arrival rule') : 'USDT'}
                </span>
            </div>
            <div className="mt-4 grid gap-3">
                <div>
                    <p className="text-xs text-muted-foreground">{t('Statistics today')}</p>
                    <p className="mt-1 break-all text-xl font-semibold">
                        <Amount value={cash.totals.today} />
                    </p>
                </div>
                <div>
                    <p className="text-xs text-muted-foreground">{t('Lifetime total')}</p>
                    <p className="mt-1 break-all text-xl font-semibold">
                        <Amount value={cash.totals.total} />
                    </p>
                </div>
            </div>
            <dl className="mt-4 divide-y border-t">
                {periods.slice(2).map(([key, text]) => (
                    <div
                        key={key}
                        className="flex items-start justify-between gap-4 py-2.5 text-sm"
                    >
                        <dt className="shrink-0 text-muted-foreground">{t(text)}</dt>
                        <dd className="min-w-0 break-all text-right font-medium">
                            <Amount value={cash.totals[key]} />
                        </dd>
                    </div>
                ))}
            </dl>
            {Object.keys(cash.native).some((asset) => asset !== 'USDT') && (
                <details className="mt-3 border-t pt-3 text-xs">
                    <summary className="cursor-pointer text-muted-foreground">
                        {t('Native currency totals')}
                    </summary>
                    {Object.entries(cash.native).map(([asset, totals]) => (
                        <div key={asset} className="mt-2 break-all">
                            {asset}: <MoneyDisplay amount={totals.total} asset={asset} />
                        </div>
                    ))}
                </details>
            )}
        </section>
    );
}

export function PlatformOverview({ overview }: { overview: PlatformOverviewData }) {
    return (
        <div data-platform-overview className="min-w-0 space-y-4">
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <p>{t('All companies · USDT · UTC+8')}</p>
                <p>
                    {t('Updated at')}: {dateTime(overview.updatedAt, overview.timezone)}
                </p>
            </div>
            {overview.partners && (
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                    <Metric label="Stock" value={overview.partners.stock} />
                    <Metric label="Theoretical balance" value={overview.partners.theoretical} />
                    <Metric label="Actual balance" value={overview.partners.actual} />
                    <Metric
                        label="Partner balance difference"
                        value={overview.partners.difference}
                    />
                    {overview.reimbursement !== undefined && (
                        <Metric label="Reimbursements" value={overview.reimbursement} />
                    )}
                </div>
            )}
            <div className="grid items-start gap-4 lg:grid-cols-3">
                {overview.inflow && (
                    <CashPanel label="Actual received top-ups" cash={overview.inflow} />
                )}
                {overview.outflow && (
                    <CashPanel
                        label="Successful withdrawals (excluding fees)"
                        cash={overview.outflow}
                        outflow
                    />
                )}
                {(overview.cards || overview.providerBalance !== undefined) && (
                    <section className="min-w-0 rounded-xl border bg-surface p-4">
                        <h2 className="font-semibold">{t('Card funds')}</h2>
                        <dl className="mt-4 divide-y">
                            {(
                                [
                                    ['Total card balance', overview.cards?.balance],
                                    ['Card funding today', overview.cards?.today],
                                    ['Cumulative card funding', overview.cards?.total],
                                    ['Third-party reference balance', overview.providerBalance],
                                ] as const
                            )
                                .filter(([, value]) => value !== undefined)
                                .map(([label, value]) => (
                                    <div
                                        key={label}
                                        className="flex items-start justify-between gap-4 py-2.5 text-sm"
                                    >
                                        <dt className="text-muted-foreground">{t(label)}</dt>
                                        <dd className="min-w-0 break-all text-right font-medium">
                                            <Amount value={value!} />
                                        </dd>
                                    </div>
                                ))}
                        </dl>
                    </section>
                )}
            </div>
            {overview.assets && (
                <div className="grid gap-4 md:grid-cols-3">
                    <Metric label="Partner total asset valuation" value={overview.assets.partner} />
                    <Metric
                        label="Non-partner total asset valuation"
                        value={overview.assets.ordinary}
                    />
                    <div className="min-w-0 rounded-xl border bg-surface p-4">
                        <p className="text-sm text-muted-foreground">{t('Bubble')}</p>
                        <div aria-label={t('Bubble')} className="mt-2 min-h-7" />
                    </div>
                </div>
            )}
            {overview.assets && overview.rateAt && (
                <p className="text-xs text-muted-foreground">
                    {t('Rate time')}: {dateTime(overview.rateAt, overview.timezone)}
                </p>
            )}
            {overview.daily && (
                <section className="min-w-0 rounded-xl border bg-surface p-4">
                    <h2 className="font-semibold">{t('Operating totals')}</h2>
                    <div className="mt-4 overflow-x-auto">
                        <table className="w-full min-w-[1100px] text-sm">
                            <thead>
                                <tr className="border-b text-muted-foreground">
                                    <th className="px-3 py-2.5 text-left">{t('Period')}</th>
                                    {activity.map(([key, label]) => (
                                        <th
                                            key={key}
                                            className="px-3 py-2.5 text-right font-medium"
                                        >
                                            {t(label)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {periods.map(([period, label]) => (
                                    <tr key={period} className="border-b last:border-0">
                                        <th className="whitespace-nowrap px-3 py-2.5 text-left font-medium">
                                            {t(label)}
                                        </th>
                                        {activity.map(([key]) => (
                                            <td
                                                key={key}
                                                className="whitespace-nowrap px-3 py-2.5 text-right"
                                            >
                                                <Amount value={overview.daily![key]![period]} />
                                            </td>
                                        ))}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </section>
            )}
            {overview.alerts && (
                <section className="min-w-0 space-y-3">
                    <h2 className="font-semibold">{t('Team alerts')}</h2>
                    <div className="grid gap-4 lg:grid-cols-3">
                        <Metric
                            label="Annual fees at 70% return progress"
                            value={overview.alerts.remaining}
                        />
                        <Metric
                            label="Deposit refunds due within 10 days"
                            value={overview.alerts.refund10}
                        />
                        <Metric
                            label="Deposit refunds due within 3 days"
                            value={overview.alerts.refund3}
                        />
                    </div>
                </section>
            )}
        </div>
    );
}
