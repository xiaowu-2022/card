import { exactAmount, displayMoney } from '@/lib/exact-amount';
import { useState, useSyncExternalStore } from 'react';
import { Link, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import {
    Eye,
    EyeOff,
    ArrowDown,
    ArrowUp,
    ArrowLeftRight,
    ChevronRight,
    ShieldCheck,
    Coins,
} from 'lucide-react';
import { t, dateTime } from '@/i18n';
import { Sheet, SheetContent, SheetTitle, SheetDescription } from '@/components/ui/sheet';

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
    const [panel, setPanel] = useState<'accounts' | 'detail' | null>(null);
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
    const depositAsset = overview.assets.find((a) => a.rails.some((r) => r.deposit));
    const withdrawalAsset = overview.assets.find((a) => a.rails.some((r) => r.withdrawal));
    const exchangeAsset = overview.assets.find((a) => a.exchange);
    const shortcuts = [
        ...(depositAsset
            ? [
                  {
                      label: 'Top up',
                      href: `/assets/operate?mode=deposit&asset=${depositAsset.asset}`,
                      icon: ArrowDown,
                  },
              ]
            : []),
        ...(withdrawalAsset
            ? [
                  {
                      label: 'Withdraw',
                      href: `/assets/operate?mode=withdrawal&asset=${withdrawalAsset.asset}`,
                      icon: ArrowUp,
                  },
              ]
            : []),
        ...(exchangeAsset
            ? [
                  {
                      label: 'Exchange',
                      href: `/assets/operate?mode=exchange&asset=${exchangeAsset.asset}`,
                      icon: ArrowLeftRight,
                  },
              ]
            : []),
        ...(usdt?.transfer
            ? [{ label: 'Transfer', href: '/wallet/transfer', icon: ArrowLeftRight }]
            : []),
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
                <p className="mt-2 text-[11px] text-muted-foreground">
                    {overview.updatedAt
                        ? t('Prices updated: {{time}}', { time: dateTime(overview.updatedAt) })
                        : t('Valuation unavailable. Original balances are unchanged.')}
                </p>
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
            <section className="rounded-2xl bg-surface px-4 pb-4 sm:px-5 sm:pb-5">
                <div className="flex min-h-14 items-center justify-between gap-3">
                    <h2 className="text-base font-medium">{t('Accounts')}</h2>
                    <button
                        onClick={() => setPanel('accounts')}
                        className="flex min-h-11 items-center gap-1 rounded-lg text-xs text-muted-foreground"
                        aria-haspopup="dialog"
                    >
                        {t('More')}
                        <ChevronRight size={14} />
                    </button>
                </div>
                <div
                    role="group"
                    aria-label={t('Accounts')}
                    className="flex gap-3 overflow-x-auto pb-1"
                >
                    {tabs.map((a) => (
                        <button
                            key={a.id}
                            aria-haspopup="dialog"
                            aria-label={t(a.label)}
                            onClick={() => {
                                setSelected(a.id);
                                setPanel('detail');
                            }}
                            className="user-asset-account flex w-[calc((100%_-_1.5rem)/3)] min-w-24 shrink-0 flex-col items-center rounded-xl bg-[#f7f6f1] px-2 py-3 text-center focus-visible:outline-2 sm:flex-1"
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
                            <span
                                title={show(a.amount)}
                                className="mt-2 block w-full truncate text-sm font-medium tabular-nums"
                            >
                                {show(a.amount)}
                            </span>
                            <span className="mt-1 block w-full break-words text-xs text-muted-foreground">
                                {t(a.label)}
                            </span>
                        </button>
                    ))}
                </div>
            </section>
            <Sheet
                open={panel !== null}
                onOpenChange={(open) => {
                    if (!open) setPanel(null);
                }}
            >
                <SheetContent
                    closeLabel={t('Close')}
                    className="user-theme inset-x-0 top-auto bottom-0 mx-auto max-h-[85dvh] w-full max-w-xl overflow-y-auto rounded-t-3xl border-0 p-6 pb-8"
                >
                    <SheetTitle>
                        {t(panel === 'accounts' ? 'Accounts' : (managed?.label ?? current.asset))}
                    </SheetTitle>
                    <SheetDescription className="sr-only">
                        {t('Account details and management')}
                    </SheetDescription>
                    {panel === 'accounts' ? (
                        <div className="mt-4 divide-y">
                            {tabs.map((a) => (
                                <button
                                    key={a.id}
                                    onClick={() => {
                                        setSelected(a.id);
                                        setPanel('detail');
                                    }}
                                    className="flex min-h-16 w-full items-center gap-3 py-3 text-left"
                                >
                                    {a.icon ? (
                                        <a.icon size={24} className="shrink-0 text-emerald-700" />
                                    ) : (
                                        <AssetIcon asset={a.asset} />
                                    )}
                                    <span className="flex-1 text-sm">{t(a.label)}</span>
                                    <span className="max-w-[50%] break-all text-right text-sm">
                                        {show(a.amount)} <small>{a.asset}</small>
                                    </span>
                                    <ChevronRight className="size-4 shrink-0" />
                                </button>
                            ))}
                        </div>
                    ) : (
                        <div data-account-detail className="mt-5">
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
                                    className="mt-5 flex min-h-12 items-center justify-center gap-2 rounded-full bg-[#171915] px-4 text-sm font-medium text-white hover:bg-[#2b3029]"
                                >
                                    {t(managed.action)}
                                    <ChevronRight size={18} />
                                </Link>
                            ) : (
                                <div className="mt-5 flex flex-wrap gap-2">
                                    {current.rails.some((r) => r.deposit) && (
                                        <Link
                                            href={`/assets/operate?mode=deposit&asset=${current.asset}`}
                                            className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-[#171915] px-4 text-sm font-medium text-white hover:bg-[#2b3029]"
                                        >
                                            <ArrowDown size={18} />
                                            {t('Top up')}
                                        </Link>
                                    )}
                                    {current.rails.some((r) => r.withdrawal) && (
                                        <Link
                                            href={`/assets/operate?mode=withdrawal&asset=${current.asset}`}
                                            className="flex min-h-12 flex-1 items-center justify-center gap-2 rounded-full bg-muted px-4 text-sm font-medium"
                                        >
                                            <ArrowUp size={18} />
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
                    )}
                </SheetContent>
            </Sheet>
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
