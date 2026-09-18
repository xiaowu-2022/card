import { ChevronRight } from 'lucide-react';
import { wealthState } from '@/lib/wealth-display';
import { useRef, useState } from 'react';
import { Head, Link, useForm, router } from '@inertiajs/react';
import { t, useClientTranslation, errorMessage, dateTime } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { exactAmount } from '@/lib/exact-amount';

export type WealthSetting = {
    asset: string;
    minimum: string;
    revision: string | null;
    products: { months: number; rate: string; enabled: boolean }[];
    available: string;
    principal: string;
    interest: string;
    net: string;
};
export type WealthOrderData = {
    id: string;
    asset: string;
    principal: string;
    rate: string;
    months: number;
    status: string;
    displayStatus: string;
    startedAt: string;
    maturesAt: string;
    paid: string;
    returnAmount: string;
    clawback: string | null;
    closedAt: string | null;
    canCancel: boolean;
    timezone: string;
};
export function WealthNotice() {
    return (
        <div className="space-y-2 rounded-xl bg-muted p-4 text-sm leading-6">
            <p>
                {t(
                    'Interest is automatically paid to your balance every month. Paid interest is recovered on early withdrawal.',
                )}
            </p>
            <p>
                {t(
                    'Early withdrawal cancels the whole deposit. You receive principal minus interest already paid; including those payments, you recover your principal.',
                )}
            </p>
        </div>
    );
}
export default function Wealth({
    settings,
    orders,
    selectedAsset = 'USDT',
    view = 'deposit',
}: {
    settings: WealthSetting[];
    selectedAsset?: string;
    view?: 'details' | 'deposit' | 'withdraw';
    orders: { data: WealthOrderData[]; prev_page_url: string | null; next_page_url: string | null };
}) {
    useClientTranslation();
    const [asset, setAsset] = useState(selectedAsset);
    const [review, setReview] = useState(false);
    const reviewButton = useRef<HTMLFormElement>(null);
    const setting = settings.find((s) => s.asset === asset)!;
    const form = useForm({
        asset,
        months: 1,
        amount: '',
        revision: setting.revision ?? '',
        request_id: crypto.randomUUID(),
        confirmed: false,
    });
    const product = setting.products.find((p) => p.months === form.data.months)!;
    const resetReview = () => {
        setReview(false);
        form.setData('confirmed', false);
        form.setData('request_id', crypto.randomUUID());
    };
    const currencySelector = (
        <div className={view === 'deposit' ? 'space-y-2' : 'flex justify-end'}>
            <label
                htmlFor="wealth-asset"
                className={view === 'deposit' ? 'block text-sm font-medium' : 'sr-only'}
            >
                {t('Select currency')}
            </label>
            <select
                id="wealth-asset"
                className={
                    view === 'deposit'
                        ? 'w-full rounded-xl border bg-surface p-3 text-base'
                        : 'max-w-full rounded-lg border bg-surface px-3 py-2 text-base'
                }
                value={asset}
                disabled={form.processing}
                onChange={(e) => {
                    const s = settings.find((s) => s.asset === e.target.value)!;
                    setAsset(s.asset);
                    router.get(`/wealth/assets/${s.asset}`, { view }, { preserveScroll: true });
                    setReview(false);
                    form.setData({
                        asset: s.asset,
                        months: 1,
                        amount: '',
                        revision: s.revision ?? '',
                        request_id: crypto.randomUUID(),
                        confirmed: false,
                    });
                }}
            >
                {settings.map((s) => (
                    <option key={s.asset}>{s.asset}</option>
                ))}
            </select>
        </div>
    );
    return (
        <UserLayout>
            <Head title={t('Wealth management')} />
            <div className="space-y-5">
                <div className={view !== 'deposit' ? 'wealth-currency-header' : undefined}>
                    <UserPageHeader
                        title={
                            view === 'details'
                                ? t('{{asset}} wealth details', { asset })
                                : `${asset} · ${t(view === 'deposit' ? 'Wealth deposit action' : view === 'withdraw' ? 'Wealth withdraw action' : 'Wealth details action')}`
                        }
                        backHref="/wealth"
                        action={view !== 'deposit' ? currencySelector : undefined}
                    />
                </div>
                {view === 'withdraw' && <WealthNotice />}
                {view === 'deposit' && currencySelector}
                {view !== 'deposit' && (
                    <dl className="grid grid-cols-2 gap-4 rounded-xl bg-surface p-4 text-sm">
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                {t('Current wealth principal')}
                            </dt>
                            <dd className="mt-1 break-all text-lg font-semibold tabular-nums">
                                <span className="block">{exactAmount(setting.principal)}</span>
                                <span className="block text-xs font-normal text-muted-foreground">
                                    {asset}
                                </span>
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs text-muted-foreground">
                                {t('Cumulative net earnings')}
                            </dt>
                            <dd className="mt-1 break-all text-lg font-semibold tabular-nums">
                                <span className="block">{exactAmount(setting.net)}</span>
                                <span className="block text-xs font-normal text-muted-foreground">
                                    {asset}
                                </span>
                            </dd>
                        </div>
                    </dl>
                )}
                {view === 'deposit' && (
                    <form
                        ref={reviewButton}
                        className="space-y-4 rounded-2xl bg-surface p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (form.processing || review) return;
                            form.setData('revision', setting.revision ?? '');
                            form.setData('confirmed', false);
                            setReview(true);
                        }}
                    >
                        <p className="text-sm">
                            {t('Available balance: {{amount}}', {
                                amount: `${exactAmount(setting.available)} ${asset}`,
                            })}
                        </p>
                        <FormField id="wealth-term" label={t('Wealth term')}>
                            <select
                                id="wealth-term"
                                className="w-full rounded-xl border bg-surface p-3"
                                disabled={review || form.processing}
                                value={form.data.months}
                                onChange={(e) => {
                                    form.setData('months', Number(e.target.value));
                                    resetReview();
                                }}
                            >
                                {setting.products.map((p) => (
                                    <option key={p.months} value={p.months}>
                                        {t('{{months}} months · {{rate}}% annual rate', {
                                            months: p.months,
                                            rate: exactAmount(p.rate),
                                        })}
                                        {!p.enabled ? ` · ${t('Unavailable')}` : ''}
                                    </option>
                                ))}
                            </select>
                        </FormField>
                        <FormField id="wealth-amount" label={t('Deposit principal')}>
                            <Input
                                id="wealth-amount"
                                className="text-base"
                                value={form.data.amount}
                                inputMode="decimal"
                                required
                                disabled={review || form.processing}
                                onChange={(e) => {
                                    form.setData('amount', e.target.value);
                                    resetReview();
                                }}
                            />
                        </FormField>
                        {setting.minimum && (
                            <p className="text-sm text-muted-foreground">
                                {t('Minimum wealth deposit: {{amount}}', {
                                    amount: `${exactAmount(setting.minimum)} ${asset}`,
                                })}
                            </p>
                        )}
                        {!product.enabled && (
                            <p className="text-sm">
                                {t('This wealth product is not available for new deposits.')}
                            </p>
                        )}
                        {Object.values(form.errors).map((e, i) => (
                            <p key={i} role="alert" className="text-sm text-destructive">
                                {errorMessage(e)}
                            </p>
                        ))}
                        <Button
                            className="w-full"
                            type="submit"
                            disabled={
                                form.processing || !product.enabled || !setting.revision || review
                            }
                        >
                            {t('Review wealth deposit')}
                        </Button>
                    </form>
                )}
                <Dialog
                    open={view === 'deposit' && review}
                    onOpenChange={(open) => {
                        if (form.processing) return;
                        setReview(open);
                        if (!open) form.setData('confirmed', false);
                    }}
                >
                    <DialogContent
                        closeLabel={t('Close')}
                        closeDisabled={form.processing}
                        className="max-h-[85dvh] overflow-y-auto overflow-x-hidden overscroll-contain"
                        onCloseAutoFocus={(e) => {
                            e.preventDefault();
                            reviewButton.current
                                ?.querySelector<HTMLButtonElement>('button[type=submit]')
                                ?.focus();
                        }}
                        onEscapeKeyDown={(e) => {
                            if (form.processing) e.preventDefault();
                        }}
                        onInteractOutside={(e) => {
                            if (form.processing) e.preventDefault();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle className="pr-10">
                                {t('Confirm wealth deposit')}
                            </DialogTitle>
                            <DialogDescription className="break-words">
                                {t(
                                    'Review wealth deposit: {{amount}}, {{months}} months, {{rate}}% annual rate.',
                                    {
                                        amount: `${exactAmount(form.data.amount)} ${asset}`,
                                        months: product.months,
                                        rate: exactAmount(product.rate),
                                    },
                                )}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (form.processing || !form.data.confirmed) return;
                                form.post('/wealth/orders', {
                                    onSuccess: () => {
                                        setReview(false);
                                        form.setData('confirmed', false);
                                    },
                                    onError: () => {
                                        setReview(false);
                                        form.setData('confirmed', false);
                                    },
                                });
                            }}
                        >
                            <p className="text-sm">
                                {t(
                                    'Principal returns automatically at maturity. No automatic renewal or compound interest.',
                                )}
                            </p>
                            <WealthNotice />
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    className="mt-1"
                                    type="checkbox"
                                    checked={form.data.confirmed}
                                    disabled={form.processing}
                                    required
                                    onChange={(e) => form.setData('confirmed', e.target.checked)}
                                />
                                {t('I confirm the term, interest rate and early withdrawal rules.')}
                            </label>
                            <div className="grid grid-cols-2 gap-3">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={form.processing}
                                    onClick={() => {
                                        setReview(false);
                                        form.setData('confirmed', false);
                                    }}
                                >
                                    {t('Back')}
                                </Button>
                                <Button
                                    type="submit"
                                    disabled={
                                        form.processing ||
                                        !form.data.confirmed ||
                                        !product.enabled ||
                                        !setting.revision
                                    }
                                >
                                    {t('Confirm wealth deposit')}
                                </Button>
                            </div>
                        </form>
                    </DialogContent>
                </Dialog>
                {view !== 'deposit' && (
                    <>
                        <div className="flex items-center justify-between gap-3">
                            <h2 className="font-semibold">{t('Wealth deposit records')}</h2>
                            {view === 'details' && (
                                <Link
                                    href={`/wealth/assets/${asset}?view=deposit`}
                                    className="inline-flex min-h-11 shrink-0 items-center justify-center rounded-xl border border-emerald-700 bg-emerald-700 px-5 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:border-emerald-800 hover:bg-emerald-800 active:bg-emerald-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600 focus-visible:ring-offset-2"
                                >
                                    {t('Wealth deposit action')}
                                </Link>
                            )}
                        </div>
                        {orders.data.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    view === 'withdraw'
                                        ? 'No deposits available for early withdrawal.'
                                        : 'No wealth deposits yet.',
                                )}
                            </p>
                        )}
                        <div className="space-y-3">
                            {orders.data.map((o) => (
                                <Link
                                    className="block space-y-3 rounded-2xl border border-black/5 bg-surface p-4 shadow-sm transition hover:border-emerald-600/30 hover:shadow-md active:bg-emerald-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-emerald-600"
                                    key={o.id}
                                    href={`/wealth/orders/${o.id}?view=${view === 'withdraw' ? 'withdraw' : 'details'}`}
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <p className="min-w-0 break-all text-xl font-semibold tabular-nums">
                                            {exactAmount(o.principal)}{' '}
                                            <span className="text-sm font-medium">{o.asset}</span>
                                        </p>
                                        <span
                                            className={`max-w-full rounded-full px-2.5 py-1 text-xs ${o.displayStatus === 'ACTIVE' ? 'bg-emerald-50 text-emerald-800' : o.displayStatus === 'AWAITING_SETTLEMENT' ? 'bg-amber-50 text-amber-800' : 'bg-muted text-muted-foreground'}`}
                                        >
                                            {wealthState(o.displayStatus)}
                                        </span>
                                    </div>
                                    <div className="space-y-1">
                                        <p className="text-sm">
                                            {t('{{months}} months · {{rate}}% annual rate', {
                                                months: o.months,
                                                rate: exactAmount(o.rate),
                                            })}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {t('Maturity date')}: {dateTime(o.maturesAt)}
                                        </p>
                                    </div>
                                    <div className="flex flex-wrap items-end justify-between gap-3 border-t pt-3">
                                        <div className="min-w-0">
                                            <p className="text-xs text-muted-foreground">
                                                {t('Total interest paid')}
                                            </p>
                                            <p className="mt-1 break-all text-sm font-medium tabular-nums">
                                                {exactAmount(o.paid)} {o.asset}
                                            </p>
                                        </div>
                                        <span className="flex items-center gap-1 text-sm font-semibold text-emerald-700">
                                            {t(
                                                view === 'withdraw'
                                                    ? 'Wealth withdraw action'
                                                    : 'Wealth view details',
                                            )}
                                            <ChevronRight
                                                className="size-4 shrink-0"
                                                aria-hidden="true"
                                            />
                                        </span>
                                    </div>
                                </Link>
                            ))}
                        </div>
                        <div className="flex justify-between">
                            {orders.prev_page_url && (
                                <Link href={orders.prev_page_url}>{t('Previous')}</Link>
                            )}
                            {orders.next_page_url && (
                                <Link href={orders.next_page_url}>{t('Next')}</Link>
                            )}
                        </div>
                    </>
                )}
            </div>
        </UserLayout>
    );
}
