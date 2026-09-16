import { exactAmount, displayMoney } from '@/lib/exact-amount';
import { useState, useSyncExternalStore } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import {
    Eye,
    EyeOff,
    ArrowDownLeft,
    ArrowUpRight,
    ArrowLeftRight,
    ChevronRight,
    ShieldCheck,
    Coins,
} from 'lucide-react';
import { t, dateTime } from '@/i18n';

export type AssetAccount = {
    asset: string;
    available: string;
    held: string;
    deposit: string;
    commission: string;
    exchange: boolean;
    transfer?: boolean;
    rails: {
        code: string;
        network: string;
        deposit: boolean;
        withdrawal: boolean;
        fee: string | null;
        minimum: string | null;
    }[];
    activity: { id: string; kind: string; amount: string; time: string }[];
    orders?: { id: string; mode: string; amount: string; state: string; time: string }[];
};
export type AssetOverview = {
    assets: AssetAccount[];
    estimate: string | null;
    updatedAt: string | null;
};
const colors: Record<string, string> = {
    USDT: 'bg-emerald-600',
    USDC: 'bg-blue-600',
    ETH: 'bg-indigo-500',
    BTC: 'bg-orange-500',
};
export function AssetIcon({ asset }: { asset: string }) {
    return (
        <span
            aria-hidden
            className={`flex size-9 shrink-0 items-center justify-center rounded-full text-lg font-semibold text-white ${colors[asset]}`}
        >
            {({ USDT: '₮', USDC: '$', ETH: 'Ξ', BTC: '₿' } as Record<string, string>)[asset]}
        </span>
    );
}
export function AssetCenter({ overview }: { overview: AssetOverview }) {
    const [selected, setSelected] = useState('USDT');
    const { auth } = usePage<SharedProps>().props;
    const key = `balance-hidden:${auth.user?.id ?? 'guest'}`;
    const hidden = useSyncExternalStore(
        (listener) => {
            window.addEventListener('balance-visibility', listener);
            return () => window.removeEventListener('balance-visibility', listener);
        },
        () => {
            try {
                return sessionStorage.getItem(key) === '1';
            } catch {
                return false;
            }
        },
        () => false,
    );
    const setHidden = (value: boolean) => {
        try {
            sessionStorage.setItem(key, value ? '1' : '0');
        } catch {
            /* Browser storage can be unavailable. */
        }
        window.dispatchEvent(new Event('balance-visibility'));
    };
    const current = overview.assets.find((a) => a.asset === selected) ?? overview.assets[0];
    if (!current) return null;
    const show = (value: string) => (hidden ? '••••••' : exactAmount(value));
    const usdt = overview.assets.find((a) => a.asset === 'USDT');
    const managedAccounts = usdt
        ? [
              {
                  id: 'deposit',
                  label: 'Security deposit',
                  amount: usdt.deposit,
                  href: '/security-deposit',
                  action: 'Manage security deposit',
                  icon: ShieldCheck,
              },
              {
                  id: 'commission',
                  label: 'Commission',
                  amount: usdt.commission,
                  href: '/promotion',
                  action: 'Manage commission',
                  icon: Coins,
              },
          ]
        : [];
    const managed = managedAccounts.find((a) => a.id === selected);
    const tabs = [
        ...managedAccounts.map((a) => ({ ...a, asset: 'USDT' })),
        ...overview.assets.map((a) => ({
            id: a.asset,
            label: a.asset,
            amount: a.available,
            asset: a.asset,
            icon: null,
        })),
    ];
    const detailAmount = managed?.amount ?? current.available;
    return (
        <div className="space-y-5">
            <section className="px-1 py-4">
                <div className="flex items-center gap-2 text-sm text-muted-foreground">
                    <h1>{t('Estimated total assets')}</h1>
                    <button
                        className="flex size-11 items-center justify-center rounded-full focus-visible:outline-2"
                        onClick={() => setHidden(!hidden)}
                        aria-label={t(hidden ? 'Show balance' : 'Hide balance')}
                    >
                        {hidden ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                </div>
                <p className="flex flex-wrap items-baseline gap-x-2 text-4xl font-semibold tracking-tight sm:text-5xl">
                    <span className="break-all">
                        {overview.estimate === null
                            ? '—'
                            : `≈ ${hidden ? '••••••' : displayMoney(overview.estimate)}`}
                    </span>
                    <span className="whitespace-nowrap text-base font-normal">USDT</span>
                </p>
                <p className="mt-3 text-xs text-muted-foreground">
                    {overview.updatedAt
                        ? t('Prices updated: {{time}}', { time: dateTime(overview.updatedAt) })
                        : t('Valuation unavailable. Original balances are unchanged.')}
                </p>
            </section>
            <section className="rounded-2xl bg-surface p-4 sm:p-5">
                <h2 className="mb-4 font-semibold">{t('Currency accounts')}</h2>
                <div
                    role="tablist"
                    aria-label={t('Currency accounts')}
                    className="flex gap-2 overflow-x-auto pb-2"
                >
                    {tabs.map((a) => (
                        <button
                            key={a.id}
                            role="tab"
                            aria-selected={selected === a.id}
                            aria-label={t(a.label)}
                            onClick={() => setSelected(a.id)}
                            className={`user-asset-account flex w-[calc((100%-1rem)/3)] min-w-24 shrink-0 flex-col items-start sm:flex-1 rounded-xl border-2 p-3 text-left transition-colors focus-visible:outline-2 ${selected === a.id ? 'border-primary bg-primary/5' : 'border-transparent bg-muted/60'}`}
                        >
                            {a.icon ? (
                                <span
                                    aria-hidden
                                    className="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-100 text-emerald-800"
                                >
                                    <a.icon size={20} />
                                </span>
                            ) : (
                                <AssetIcon asset={a.asset} />
                            )}
                            <span className="mb-1 mt-2 block w-full break-words text-sm font-medium">
                                {t(a.label)}
                            </span>
                            <span
                                title={show(a.amount)}
                                className="mt-auto block w-full truncate text-xs text-muted-foreground"
                            >
                                {show(a.amount)}
                            </span>
                        </button>
                    ))}
                </div>
                <div role="tabpanel" className="mt-5 border-t pt-5">
                    <p className="text-sm text-muted-foreground">
                        {t(managed?.label ?? 'Available balance')} ·{' '}
                        {managed ? 'USDT' : current.asset}
                    </p>
                    <p
                        className={`mt-1 break-all font-semibold tabular-nums ${show(detailAmount).length > 18 ? 'text-xl sm:text-2xl' : 'text-3xl'}`}
                    >
                        {show(detailAmount)}
                    </p>
                    {managed ? (
                        <Link
                            href={managed.href}
                            className="mt-5 flex min-h-12 items-center justify-center gap-2 rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground"
                        >
                            {t(managed.action)}
                            <ChevronRight size={18} />
                        </Link>
                    ) : (
                        <div className="mt-5 flex flex-wrap gap-2">
                            {current.rails.some((r) => r.deposit) && (
                                <Link
                                    href={`/assets/operate?mode=deposit&asset=${current.asset}`}
                                    className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-primary px-4 text-sm font-medium text-primary-foreground"
                                >
                                    <ArrowDownLeft size={18} />
                                    {t('Top up')}
                                </Link>
                            )}
                            {current.rails.some((r) => r.withdrawal) && (
                                <Link
                                    href={`/assets/operate?mode=withdrawal&asset=${current.asset}`}
                                    className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-muted px-4 text-sm font-medium"
                                >
                                    <ArrowUpRight size={18} />
                                    {t('Withdraw')}
                                </Link>
                            )}
                            {current.asset === 'USDT' && current.transfer && (
                                <Link
                                    href="/wallet/transfer"
                                    className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-muted px-4 text-sm font-medium"
                                >
                                    <ArrowLeftRight size={18} />
                                    {t('Transfer')}
                                </Link>
                            )}
                            {current.exchange && (
                                <Link
                                    href={`/assets/operate?mode=exchange&asset=${current.asset}`}
                                    className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-muted px-4 text-sm font-medium"
                                >
                                    <ArrowLeftRight size={18} />
                                    {t('Exchange')}
                                </Link>
                            )}
                        </div>
                    )}
                </div>
            </section>
            {!managed && !!current.orders?.length && (
                <section>
                    <h2 className="mb-2 font-semibold">{t('Recent requests')}</h2>
                    {current.orders.map((order) => (
                        <Link
                            key={order.id}
                            href={`/assets/operate?mode=${order.mode}&asset=${current.asset}&order=${order.id}`}
                            className="flex min-h-14 items-center justify-between gap-3 border-b py-3 text-sm"
                        >
                            <div>
                                <p>
                                    {t(
                                        order.mode === 'exchange'
                                            ? 'Exchange'
                                            : order.mode === 'withdrawal'
                                              ? 'Withdrawal'
                                              : 'Top up',
                                    )}
                                </p>
                                <p className="mt-1 text-xs text-muted-foreground">
                                    {t(order.state)}
                                </p>
                            </div>
                            <span className="break-all text-right">
                                {show(order.amount)} {current.asset}
                            </span>
                            <ChevronRight className="size-4 shrink-0" />
                        </Link>
                    ))}
                </section>
            )}
            {!managed && (
                <section>
                    <h2 className="mb-2 font-semibold">
                        {t('Account activity')} · {current.asset}
                        <Link
                            className="float-right py-2 text-sm font-normal underline"
                            href={`/assets/${current.asset}/activity`}
                        >
                            {t('More')}
                        </Link>
                    </h2>
                    {current.activity.length === 0 ? (
                        <p className="py-6 text-center text-sm text-muted-foreground">
                            {t('No activity yet')}
                        </p>
                    ) : (
                        current.activity.map((row) => (
                            <div
                                key={row.id}
                                className="flex items-center justify-between gap-3 border-b py-4 text-sm"
                            >
                                <div>
                                    <p>{t(row.kind)}</p>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {dateTime(row.time)}
                                    </p>
                                </div>
                                <p className="max-w-[55%] break-all text-right font-medium tabular-nums">
                                    {show(row.amount)}
                                    <span className="ml-1 text-xs text-muted-foreground">
                                        {current.asset}
                                    </span>
                                </p>
                            </div>
                        ))
                    )}
                </section>
            )}
        </div>
    );
}
