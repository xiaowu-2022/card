import { systemMoney } from '@/lib/system-money';
import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { History, ShieldCheck } from 'lucide-react';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount, SharedProps } from '@/types/global';
import { DepositRefundControls, type DepositRefund } from '@/components/user/DepositRefundControls';
import { DepositTopupForm } from '@/components/user/DepositTopupForm';

type Money = { amount: MoneyAmount; asset: string };
type Preview = {
    current: Money;
    required: Money;
    remaining: Money;
    available: Money;
    availableAfter: Money | null;
    canFund: boolean;
    satisfied: boolean;
    agentExempt: boolean;
    topupAvailable: boolean;
    minimumTopup: Money;
    refund: DepositRefund;
};

export default function SecurityDeposit({ preview }: { preview: Preview }) {
    useClientTranslation();
    const form = useForm({
        request_id: crypto.randomUUID(),
        expected_remaining: preview.remaining.amount,
    });
    const formError = usePage<SharedProps & { errors: { form?: string } }>().props.errors.form;

    return (
        <UserLayout>
            <Head title={t('Security deposit')} />
            <div className="space-y-6 sm:space-y-8">
                <div className="relative">
                    <UserPageHeader title={t('Security deposit')} backHref="/dashboard" />
                    <Link
                        href="/security-deposit/history"
                        className="absolute right-0 top-0 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground"
                    >
                        <History className="size-4" aria-hidden="true" />
                        {t('Security deposit history')}
                    </Link>
                </div>
                {preview.agentExempt ? (
                    <section className="rounded-2xl bg-[#f2f6ef] p-5">
                        <h2 className="font-semibold">
                            {t('Active agents do not need a security deposit.')}
                        </h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {t('Current deposit')}: <MoneyDisplay {...preview.current} />
                        </p>
                    </section>
                ) : preview.satisfied ? (
                    <section className="rounded-[var(--user-radius-md)] border border-emerald-200 bg-emerald-50 p-5 text-emerald-950">
                        <div className="flex gap-3">
                            <ShieldCheck className="mt-0.5 size-5 shrink-0" aria-hidden="true" />
                            <div className="min-w-0 flex-1">
                                <h2 className="font-semibold">{t('Security deposit')}</h2>
                                <p className="mt-2 break-words text-2xl font-semibold tracking-tight">
                                    <MoneyDisplay {...preview.current} />
                                </p>
                            </div>
                        </div>
                    </section>
                ) : !preview.canFund ? (
                    <>
                        <UserStatusBanner
                            tone="warning"
                            title={t(
                                'You need {{value1}} {{value2}} to complete your security deposit',
                                {
                                    amount: systemMoney(preview.remaining.amount),
                                },
                            )}
                            description={t('Available balance: {{amount}}', {
                                amount: systemMoney(preview.available.amount),
                            })}
                        />
                        {!preview.refund.pendingId && (
                            <DepositTopupForm
                                minimum={preview.minimumTopup.amount}
                                asset={preview.minimumTopup.asset}
                                enabled={preview.topupAvailable}
                            />
                        )}
                    </>
                ) : (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <span className="grid size-11 place-items-center rounded-full bg-[var(--user-primary-soft)] text-[var(--user-primary-readable)]">
                            <ShieldCheck className="size-5" />
                        </span>
                        <h2 className="mt-5 text-xl font-semibold">{t('Review deposit')}</h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            {t('Funds will be held separately from your available balance.')}
                        </p>
                        <dl className="mt-6 divide-y border-y text-sm">
                            {[
                                ['Required', preview.required],
                                ['Already deposited', preview.current],
                                ['Deposit now', preview.remaining],
                                ['Available after', preview.availableAfter!],
                            ].map(([label, money]) => (
                                <div
                                    className="flex items-center justify-between gap-4 py-4"
                                    key={label as string}
                                >
                                    <dt className="text-muted-foreground">{t(label as string)}</dt>
                                    <dd className="font-semibold">
                                        <MoneyDisplay {...(money as Money)} compact />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {formError ? (
                            <p className="mt-4 text-sm text-destructive">
                                {errorMessage(formError)}
                            </p>
                        ) : null}
                        <Button
                            className="mt-6 w-full sm:w-auto"
                            disabled={form.processing}
                            onClick={() => form.post('/security-deposit/fund')}
                        >
                            {form.processing ? t('Confirming…') : t('Confirm deposit')}
                        </Button>
                    </section>
                )}
                {(preview.current.amount !== '0.00000000' || preview.refund.pendingId) && (
                    <DepositRefundControls refund={preview.refund} />
                )}
            </div>
        </UserLayout>
    );
}
