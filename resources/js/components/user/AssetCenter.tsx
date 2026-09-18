import { exactAmount, displayMoney } from '@/lib/exact-amount';
import { useState, useSyncExternalStore } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import {
    Eye,
    EyeOff,
    ArrowDown,
    ArrowDownLeft,
    ArrowUpRight,
    ArrowUp,
    ArrowLeftRight,
    ChevronRight,
    ShieldCheck,
    Coins,
} from 'lucide-react';
import { t, dateTime } from '@/i18n';
import { GrowthCampaign } from '@/components/user/GrowthCampaign';

export type AssetAccount = {
    asset: string;
    available: string;
    held: string;
    deposit: string;
    exchange: boolean;
    exchangeUnavailableReason?: string | null;
    transfer?: boolean;
    rails: {
        code: string;
        network: string;
        deposit: boolean;
        withdrawal: boolean;
        feePercent: string | null;
        minimum: string | null;
    }[];
    activity: { id: string; kind: string; amount: string; time: string }[];
    orders?: { id: string; mode: string; amount: string; state: string; time: string }[];
};
export type AccountActivation = {
    qualified: boolean;
    agent: boolean;
    rank: number;
    endsAt: string | null;
    ordinaryAvailable: boolean;
    depositSatisfied: boolean;
    depositRequired: string;
    depositCurrent: string;
    depositRemaining: string;
    refundPending: boolean;
};
export type AssetOverview = {
    activation: AccountActivation;
    cumulativeCommission: string;
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
export function AssetCenter({
    overview,
    prerequisiteHref,
}: {
    overview: AssetOverview;
    prerequisiteHref?: string;
}) {
    const [expanded, setExpanded] = useState(false);
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
    const recentActivity = overview.assets
        .flatMap((account) => account.activity.map((row) => ({ ...row, asset: account.asset })))
        .sort((a, b) => b.time.localeCompare(a.time) || b.id.localeCompare(a.id))
        .slice(0, 5);
    const current = overview.assets.find((a) => a.asset === 'USDT') ?? overview.assets[0];
    if (!current) return null;
    const show = (value: string | null) =>
        hidden ? '••••••' : value === null ? '—' : exactAmount(value);
    const usdt = overview.assets.find((a) => a.asset === 'USDT');
    const managedAccounts = usdt
        ? [
              {
                  id: 'deposit',
                  label: 'Security deposit',
                  amount: usdt.deposit,
                  href: '/security-deposit',
                  icon: ShieldCheck,
              },
          ]
        : [];
    const tabs = [
        ...managedAccounts.map((a) => ({ ...a, asset: 'USDT' })),
        ...overview.assets.map((a) => ({
            id: a.asset,
            label: a.asset,
            amount: a.available,
            asset: a.asset,
            icon: null,
            href: `/funds?asset=${a.asset}`,
        })),
        ...(usdt && expanded
            ? [
                  {
                      id: 'wealth',
                      label: 'Wealth management',
                      amount: null,
                      asset: 'USDT',
                      href: '/wealth',
                      icon: Coins,
                  },
              ]
            : []),
    ];
    const depositAsset = overview.assets.find((a) => a.rails.some((r) => r.deposit));
    const withdrawalAsset = overview.assets.find((a) => a.rails.some((r) => r.withdrawal));
    const exchangeAsset =
        overview.assets.find((a) => a.asset === current.asset && a.asset !== 'USDT') ??
        overview.assets.find((a) => a.exchange) ??
        overview.assets.find((a) => a.asset !== 'USDT');
    const shortcuts = [
        {
            label: 'Top up',
            href:
                prerequisiteHref ??
                `/assets/operate?mode=deposit&asset=${depositAsset?.asset ?? 'USDT'}`,
            icon: ArrowDown,
        },
        {
            label: 'Withdraw',
            href:
                prerequisiteHref ??
                `/assets/operate?mode=withdrawal&asset=${withdrawalAsset?.asset ?? 'USDT'}`,
            icon: ArrowUp,
        },
        {
            label: 'Exchange',
            href:
                prerequisiteHref ??
                `/assets/operate?mode=exchange&asset=${exchangeAsset?.asset ?? 'USDC'}`,
            icon: ArrowLeftRight,
        },
        {
            label: 'Transfer',
            href: prerequisiteHref ?? (usdt?.transfer ? '/wallet/transfer' : '/wallet'),
            icon: ArrowLeftRight,
        },
    ];
    return (
        <div className="space-y-4">
            <section className="px-2 py-1 text-center">
                <div className="flex items-center justify-center text-xs text-muted-foreground">
                    <h1>{t('Estimated total assets')}</h1>
                    <button
                        className="flex size-11 items-center justify-center rounded-full focus-visible:outline-2"
                        onClick={() => setHidden(!hidden)}
                        aria-label={t(hidden ? 'Show balance' : 'Hide balance')}
                    >
                        {hidden ? <EyeOff size={18} /> : <Eye size={18} />}
                    </button>
                </div>
                <p className="flex flex-wrap items-baseline justify-center gap-x-2 text-3xl font-semibold tracking-tight sm:text-4xl">
                    <span className="break-all">
                        {overview.estimate === null
                            ? '—'
                            : `≈ ${hidden ? '••••••' : displayMoney(overview.estimate)}`}
                    </span>
                    <span className="whitespace-nowrap text-base font-normal">USDT</span>
                </p>
                {(overview.estimate === null || overview.updatedAt) && (
                    <p className="mt-2 text-[11px] text-muted-foreground">
                        {overview.estimate === null
                            ? t('Valuation unavailable. Original balances are unchanged.')
                            : t('Prices updated: {{time}}', {
                                  time: dateTime(overview.updatedAt!),
                              })}
                    </p>
                )}
            </section>
            {shortcuts.length > 0 && (
                <nav
                    aria-label={t('Account actions')}
                    className="flex justify-evenly gap-2 px-1 pb-1"
                >
                    {shortcuts.map((action) => (
                        <Link
                            key={action.label}
                            href={action.href}
                            className="flex min-w-0 flex-1 flex-col items-center gap-2 rounded-xl text-center text-xs text-muted-foreground focus-visible:outline-2"
                        >
                            <span className="flex size-12 items-center justify-center rounded-full bg-[#171915] text-white sm:size-14">
                                <action.icon size={22} />
                            </span>
                            <span className="break-words">{t(action.label)}</span>
                        </Link>
                    ))}
                </nav>
            )}
            {!overview.activation.qualified && (
                <section
                    aria-label={t('Account activation')}
                    className="flex items-center gap-3 rounded-2xl bg-[#f2f6ef] px-4 py-3"
                >
                    <ShieldCheck className="size-5 shrink-0 text-emerald-800" aria-hidden="true" />
                    <div className="min-w-0 flex-1">
                        <h2 className="text-sm font-semibold">{t('Account pending activation')}</h2>
                        <p className="mt-1 text-xs leading-5 text-muted-foreground">
                            {t('Pay a member deposit or choose an agent level to activate.')}
                        </p>
                    </div>
                    <Link
                        href="/promotion/membership"
                        className="shrink-0 rounded-full bg-[#193c34] px-3 py-2.5 text-xs font-medium text-white"
                    >
                        {t('Activate now')}
                    </Link>
                </section>
            )}
            <section className="rounded-2xl bg-surface px-4 pb-4 sm:px-5 sm:pb-5">
                <div className="flex min-h-14 items-center justify-between gap-3">
                    <h2 className="text-base font-medium">{t('Accounts')}</h2>
                    <button
                        onClick={() => setExpanded(!expanded)}
                        className="flex min-h-11 items-center gap-1 rounded-lg text-xs text-muted-foreground"
                        aria-expanded={expanded}
                        aria-controls="asset-accounts"
                    >
                        {t(expanded ? 'Show less' : 'More')}
                        <ChevronRight size={14} className={expanded ? '-rotate-90' : ''} />
                    </button>
                </div>
                <div
                    id="asset-accounts"
                    role="group"
                    aria-label={t('Accounts')}
                    className={
                        expanded
                            ? 'grid grid-cols-3 gap-3 pb-1 sm:grid-cols-6'
                            : 'flex gap-3 overflow-x-auto pb-1'
                    }
                >
                    {tabs.map((a) => {
                        const className = `user-asset-account flex ${expanded ? 'min-w-0' : 'w-[calc((100%_-_1.5rem)/3)] min-w-24 shrink-0 sm:flex-1'} flex-col items-center rounded-xl bg-[#f7f6f1] px-2 py-3 text-center focus-visible:outline-2`;
                        const content = (
                            <>
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
                                {a.id !== 'wealth' && (
                                    <span
                                        title={a.icon ? `${show(a.amount)} USDT` : show(a.amount)}
                                        className="mt-2 flex w-full min-w-0 items-baseline justify-center gap-1 text-sm font-medium tabular-nums"
                                    >
                                        <span className="truncate">{show(a.amount)}</span>
                                        {a.icon && (
                                            <span className="shrink-0 text-[10px] font-normal">
                                                USDT
                                            </span>
                                        )}
                                    </span>
                                )}
                                <span
                                    className={`${a.id === 'wealth' ? 'mt-2' : 'mt-1'} block w-full break-words text-xs text-muted-foreground`}
                                >
                                    {t(a.label)}
                                </span>
                            </>
                        );
                        return (
                            <Link
                                key={a.id}
                                href={a.href}
                                aria-label={t(a.label)}
                                className={className}
                            >
                                {content}
                            </Link>
                        );
                    })}
                </div>
            </section>
            <GrowthCampaign variant="account" />
            <section
                className="rounded-2xl bg-surface px-4 pb-2 sm:px-5"
                aria-labelledby="recent-transactions-title"
            >
                <div className="flex min-h-14 items-center justify-between gap-3">
                    <h2 id="recent-transactions-title" className="text-base font-semibold">
                        {t('Latest transactions')}
                    </h2>
                    <Link
                        href="/funds"
                        className="flex min-h-11 items-center gap-1 rounded-lg text-xs text-muted-foreground focus-visible:outline-2"
                    >
                        {t('More')}
                        <ChevronRight size={14} aria-hidden="true" />
                    </Link>
                </div>
                {recentActivity.length === 0 ? (
                    <p className="py-7 text-center text-sm text-muted-foreground">
                        {t('No activity yet')}
                    </p>
                ) : (
                    <div className="divide-y divide-black/5">
                        {recentActivity.map((row) => {
                            const debit = row.amount.startsWith('-');
                            const Icon = debit ? ArrowUpRight : ArrowDownLeft;
                            const amount = exactAmount(row.amount);
                            return (
                                <div key={row.id} className="flex items-start gap-3 py-4 text-sm">
                                    <span
                                        className={`flex size-8 shrink-0 items-center justify-center rounded-full ${debit ? 'bg-muted text-slate-600' : 'bg-emerald-50 text-emerald-700'}`}
                                    >
                                        <Icon size={16} aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap justify-between gap-x-3 gap-y-1">
                                            <p className="font-medium">{t(row.kind)}</p>
                                            <p
                                                className={`min-w-0 break-all font-semibold tabular-nums ${debit ? '' : 'text-emerald-700'}`}
                                            >
                                                {hidden
                                                    ? '••••••'
                                                    : debit || amount === '0'
                                                      ? amount
                                                      : `+${amount}`}{' '}
                                                <span className="whitespace-nowrap text-xs font-normal">
                                                    {row.asset}
                                                </span>
                                            </p>
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {dateTime(row.time)}
                                        </p>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                )}
            </section>
            {!!current.orders?.length && (
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
        </div>
    );
}
