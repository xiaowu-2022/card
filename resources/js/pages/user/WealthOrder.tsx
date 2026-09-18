import { ArrowUpFromLine, CalendarDays, Check, Clock3, Minus } from 'lucide-react';
import { wealthState } from '@/lib/wealth-display';
import { useRef, useState } from 'react';
import { Head, useForm, router } from '@inertiajs/react';
import { t, useClientTranslation, errorMessage, dateTime } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { exactAmount } from '@/lib/exact-amount';
import type { WealthOrderData } from './Wealth';
export default function WealthOrder({
    order,
    startWithdrawal = false,
}: {
    startWithdrawal?: boolean;
    order: WealthOrderData & {
        schedule: { month: number; dueAt: string; amount: string; settledAt: string | null }[];
    };
}) {
    useClientTranslation();
    const [review, setReview] = useState(startWithdrawal && order.canCancel);
    const pageRef = useRef<HTMLDivElement>(null);
    const closeReview = () => {
        setReview(false);
        form.reset('current_password', 'confirmed');
    };
    const form = useForm({
        request_id: crypto.randomUUID(),
        current_password: '',
        expected_paid: order.paid,
        confirmed: false,
    });
    return (
        <UserLayout>
            <Head title={t('Wealth deposit details')} />
            <div ref={pageRef} className="space-y-5">
                <UserPageHeader
                    title={t('Wealth deposit details')}
                    backHref={`/wealth/assets/${order.asset}?view=${startWithdrawal ? 'withdraw' : 'details'}`}
                />
                <section className="rounded-3xl bg-gradient-to-br from-emerald-100 to-lime-50 p-5 text-slate-900">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
                        <span className="text-sm font-semibold">{order.asset}</span>
                        <span
                            className={`rounded-full px-3 py-1 text-xs font-medium ${order.displayStatus === 'ACTIVE' ? 'bg-white/70 text-emerald-800' : order.displayStatus === 'AWAITING_SETTLEMENT' ? 'bg-amber-50 text-amber-800' : 'bg-white/70 text-slate-600'}`}
                        >
                            {wealthState(order.displayStatus)}
                        </span>
                    </div>
                    <p className="text-xs text-slate-600">{t('Deposit principal')}</p>
                    <p className="mt-1 break-all text-3xl font-semibold tracking-tight tabular-nums">
                        {exactAmount(order.principal)}{' '}
                        <span className="whitespace-nowrap text-base font-medium">
                            {order.asset}
                        </span>
                    </p>
                    <div className="mt-5 border-t border-emerald-900/10 pt-3">
                        <p className="text-xs text-slate-600">{t('Total interest paid')}</p>
                        <p className="mt-1 break-all text-lg font-semibold tabular-nums">
                            {exactAmount(order.paid)}{' '}
                            <span className="whitespace-nowrap text-sm font-medium">
                                {order.asset}
                            </span>
                        </p>
                    </div>
                </section>
                <section className="rounded-2xl border border-black/5 bg-surface p-4 shadow-sm">
                    <h2 className="mb-3 flex items-center gap-2 font-semibold">
                        <CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />
                        {t('Wealth deposit information')}
                    </h2>
                    <dl className="divide-y text-sm">
                        {(
                            [
                                [
                                    'Wealth term',
                                    t('{{months}} months · {{rate}}% annual rate', {
                                        months: order.months,
                                        rate: exactAmount(order.rate),
                                    }),
                                ],
                                ['Deposit date', dateTime(order.startedAt)],
                                ['Maturity date', dateTime(order.maturesAt)],
                            ] as [string, string][]
                        ).map(([label, value]) => (
                            <div
                                key={label}
                                className="flex flex-wrap items-start justify-between gap-x-4 gap-y-1 py-3 last:pb-0"
                            >
                                <dt className="text-muted-foreground">{t(label)}</dt>
                                <dd className="min-w-0 break-words font-medium tabular-nums">
                                    {value}
                                </dd>
                            </div>
                        ))}
                    </dl>
                    {order.closedAt && (
                        <div className="mt-4 rounded-xl bg-muted p-3">
                            <p className="break-words text-sm font-medium">
                                {t('Principal returned: {{amount}}', {
                                    amount: `${exactAmount(order.returnAmount)} ${order.asset}`,
                                })}
                            </p>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {dateTime(order.closedAt)}
                            </p>
                        </div>
                    )}
                </section>
                {order.canCancel && (
                    <Button
                        data-wealth-withdraw
                        variant="secondary"
                        className="h-auto min-h-12 w-full gap-2 rounded-xl border-emerald-700/25 py-3 text-emerald-800 hover:bg-emerald-50"
                        onClick={() => {
                            router.reload({ only: ['order'], onSuccess: () => setReview(true) });
                        }}
                    >
                        <ArrowUpFromLine className="size-4 shrink-0" aria-hidden="true" />
                        {t('Withdraw entire deposit early')}
                    </Button>
                )}
                <Dialog
                    open={order.canCancel && review}
                    onOpenChange={(open) => {
                        if (!open && !form.processing) closeReview();
                    }}
                >
                    <DialogContent
                        closeLabel={t('Close')}
                        closeDisabled={form.processing}
                        className="max-h-[85dvh] overflow-y-auto overflow-x-hidden overscroll-contain"
                        onEscapeKeyDown={(e) => {
                            if (form.processing) e.preventDefault();
                        }}
                        onInteractOutside={(e) => {
                            if (form.processing) e.preventDefault();
                        }}
                        onCloseAutoFocus={(e) => {
                            e.preventDefault();
                            pageRef.current
                                ?.querySelector<HTMLButtonElement>('[data-wealth-withdraw]')
                                ?.focus();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle className="pr-10">
                                {t('Confirm early withdrawal')}
                            </DialogTitle>
                            <DialogDescription className="break-words">
                                {t('Deposit principal')}: {exactAmount(order.principal)}{' '}
                                {order.asset}
                            </DialogDescription>
                        </DialogHeader>
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                if (form.processing || !form.data.confirmed) return;
                                form.transform((data) => ({ ...data, expected_paid: order.paid }));
                                form.post(`/wealth/orders/${order.id}/cancel`, {
                                    onSuccess: () => setReview(false),
                                    onError: () => {
                                        setReview(false);
                                        router.reload({ only: ['order'] });
                                    },
                                    onFinish: () => form.reset('current_password', 'confirmed'),
                                });
                            }}
                        >
                            <p>
                                {t('Interest recovered: {{amount}}', {
                                    amount: `${exactAmount(order.paid)} ${order.asset}`,
                                })}
                            </p>
                            <p className="font-semibold">
                                {t('Amount returned now: {{amount}}', {
                                    amount: `${exactAmount(order.returnAmount)} ${order.asset}`,
                                })}
                            </p>
                            <FormField id="wealth-password" label={t('Current password')}>
                                <Input
                                    id="wealth-password"
                                    className="text-base"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    disabled={form.processing}
                                    value={form.data.current_password}
                                    onChange={(e) =>
                                        form.setData('current_password', e.target.value)
                                    }
                                />
                            </FormField>
                            <label className="flex items-start gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    required
                                    disabled={form.processing}
                                    checked={form.data.confirmed}
                                    onChange={(e) => form.setData('confirmed', e.target.checked)}
                                />
                                {t(
                                    'I confirm cancellation of the entire deposit and recovery of all paid interest.',
                                )}
                            </label>
                            <Button
                                className="w-full"
                                disabled={form.processing || !form.data.confirmed}
                            >
                                {t('Confirm early withdrawal')}
                            </Button>
                        </form>
                    </DialogContent>
                </Dialog>
                {Object.values(form.errors).map((e, i) => (
                    <p key={i} role="alert" className="text-sm text-destructive">
                        {errorMessage(e)}
                    </p>
                ))}
                <section className="rounded-2xl border border-black/5 bg-surface p-4 shadow-sm">
                    <h2 className="mb-2 font-semibold">{t('Monthly interest schedule')}</h2>
                    <div className="divide-y">
                        {order.schedule.map((row) => {
                            const paid = row.settledAt !== null;
                            const cancelled = !paid && order.status === 'CANCELLED';
                            const Icon = paid ? Check : cancelled ? Minus : Clock3;
                            return (
                                <div key={row.month} className="flex gap-3 py-4 last:pb-1">
                                    <span
                                        className={`mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full ${paid ? 'bg-emerald-50 text-emerald-700' : 'bg-muted text-muted-foreground'}`}
                                    >
                                        <Icon className="size-4" aria-hidden="true" />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-start justify-between gap-x-3 gap-y-1">
                                            <p className="text-sm font-medium tabular-nums">
                                                {dateTime(row.dueAt)}
                                            </p>
                                            <p className="break-all text-sm font-semibold tabular-nums">
                                                {exactAmount(row.amount)}{' '}
                                                <span className="whitespace-nowrap">
                                                    {order.asset}
                                                </span>
                                            </p>
                                        </div>
                                        <p
                                            className={`mt-1 text-xs ${paid ? 'text-emerald-700' : 'text-muted-foreground'}`}
                                        >
                                            {t(
                                                paid
                                                    ? 'Interest paid'
                                                    : cancelled
                                                      ? 'Interest cancelled'
                                                      : 'Scheduled interest',
                                            )}
                                        </p>
                                        {row.settledAt && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {dateTime(row.settledAt)}
                                            </p>
                                        )}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </section>
            </div>
        </UserLayout>
    );
}
