import {
    displayMoney,
    withdrawalPercentageFee,
    withdrawalRemainder,
    withdrawalReceiveAmount,
} from '@/lib/exact-amount';
import { WithdrawalAmounts } from '@/components/user/WithdrawalAmounts';
import { t, useClientTranslation, errorMessage } from '@/i18n';
import { Head, Link, useForm } from '@inertiajs/react';
import { History } from 'lucide-react';
import { useState } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

export default function Withdraw({
    available,
    network,
    feePercent,
}: {
    available: { amount: MoneyAmount; asset: string };
    network: string;
    feePercent: string | null;
}) {
    useClientTranslation();
    const [reviewing, setReviewing] = useState(false);
    const [reviewedFee, setReviewedFee] = useState('0');
    // Unkeyed form: never remember the raw address in history or persistent storage.
    const withdrawal = useForm<{
        request_id: string;
        address: string;
        amount: string;
        confirmed: boolean;
        form?: string;
    }>({
        request_id: crypto.randomUUID(),
        address: '',
        amount: '',
        confirmed: false,
    });
    const normalizedAddress = withdrawal.data.address.trim();
    const validAddress = /^T[1-9A-HJ-NP-Za-km-z]{33}$/.test(normalizedAddress);
    const availableAfter = withdrawalRemainder(withdrawal.data.amount, available.amount);
    const calculatedFee = withdrawalPercentageFee(withdrawal.data.amount, feePercent, 'USDT');
    const fee = reviewing ? reviewedFee : (calculatedFee ?? '0');
    const receiveAmount = withdrawalReceiveAmount(withdrawal.data.amount, fee);
    const canReview =
        calculatedFee !== null && validAddress && availableAfter !== null && receiveAmount !== null;
    const addressError =
        withdrawal.data.address && !validAddress ? t('Enter a valid TRON address.') : undefined;
    const amountError =
        withdrawal.data.amount && availableAfter === null
            ? withdrawalRemainder(withdrawal.data.amount, '999999999999.99999999') === null
                ? t('Enter a positive amount with at most 2 decimal places.')
                : t('Your available balance is not enough for this withdrawal.')
            : withdrawal.data.amount && receiveAmount === null
              ? t('Withdrawal amount must be greater than the fee.')
              : undefined;

    return (
        <UserLayout>
            <Head title={t('Withdraw USDT')} />
            <div className="space-y-6 sm:space-y-8">
                <div className="relative">
                    <UserPageHeader title={t('Withdraw')} backHref="/dashboard" />
                    <Link
                        href="/wallet/withdrawals"
                        className="absolute right-0 top-0 inline-flex min-h-11 items-center gap-1.5 text-sm font-semibold text-muted-foreground hover:text-foreground"
                    >
                        <History className="size-4" aria-hidden="true" />
                        {t('Withdrawal history')}
                    </Link>
                </div>
                {!reviewing ? (
                    <>
                        <div>
                            <p className="text-sm text-muted-foreground">{t('Available')}</p>
                            <p className="mt-1 text-3xl font-semibold">
                                <MoneyDisplay {...available} compact />
                            </p>
                        </div>
                        <form
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (canReview) {
                                    setReviewedFee(calculatedFee!);
                                    setReviewing(true);
                                }
                            }}
                        >
                            <FormField
                                id="withdrawal-address"
                                label={t('Withdrawal address')}
                                error={errorMessage(withdrawal.errors.address) || addressError}
                            >
                                <Input
                                    id="withdrawal-address"
                                    value={withdrawal.data.address}
                                    onChange={(event) =>
                                        withdrawal.setData('address', event.target.value)
                                    }
                                    placeholder={t('T...')}
                                    autoComplete="off"
                                    autoCapitalize="none"
                                    spellCheck={false}
                                    maxLength={100}
                                    required
                                />
                            </FormField>
                            <FormField
                                id="withdrawal-amount"
                                label={t('Amount')}
                                error={errorMessage(withdrawal.errors.amount) || amountError}
                            >
                                <Input
                                    id="withdrawal-amount"
                                    inputMode="decimal"
                                    value={withdrawal.data.amount}
                                    onChange={(event) =>
                                        withdrawal.setData('amount', event.target.value)
                                    }
                                    placeholder="0.00"
                                    maxLength={15}
                                    required
                                />
                            </FormField>
                            <div className="flex justify-between gap-4 rounded-lg bg-muted px-4 py-3 text-sm">
                                <span className="text-muted-foreground">{t('Network')}</span>
                                <span className="font-medium">USDT ({network})</span>
                            </div>
                            {feePercent === null && (
                                <p className="text-sm text-destructive">{t('Not configured')}</p>
                            )}
                            <WithdrawalAmounts fee={fee} receive={receiveAmount} />
                            {withdrawal.errors.form && (
                                <p role="alert" className="text-sm text-destructive">
                                    {errorMessage(withdrawal.errors.form)}
                                </p>
                            )}
                            <div className="flex justify-center">
                                <Button disabled={!canReview || withdrawal.processing}>
                                    {t('Withdraw')}
                                </Button>
                            </div>
                        </form>
                    </>
                ) : (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <p className="text-sm text-muted-foreground">{t('You are withdrawing')}</p>
                        <p className="mt-2 text-3xl font-semibold">
                            {displayMoney(withdrawal.data.amount)} USDT
                        </p>
                        <dl className="mt-6 divide-y border-y text-sm">
                            <div className="flex justify-between gap-4 py-4">
                                <dt className="text-muted-foreground">{t('Network')}</dt>
                                <dd className="font-medium">{network}</dd>
                            </div>
                            <div className="flex flex-col gap-2 py-4">
                                <dt className="text-muted-foreground">{t('Address')}</dt>
                                <dd className="break-all font-medium">{normalizedAddress}</dd>
                            </div>
                            <div className="flex justify-between gap-4 py-4">
                                <dt className="text-muted-foreground">{t('Available after')}</dt>
                                <dd className="font-medium">
                                    {availableAfter === null ? '—' : displayMoney(availableAfter)}{' '}
                                    USDT
                                </dd>
                            </div>
                        </dl>
                        <div className="mt-4">
                            <WithdrawalAmounts fee={fee} receive={receiveAmount} />
                        </div>
                        <p className="mt-4 text-sm leading-6 text-muted-foreground">
                            {t(
                                'Check the address and amount carefully. Transfers sent to an incorrect address cannot be recovered. Your request will be reviewed before payment.',
                            )}
                        </p>
                        <div className="mt-6 flex flex-wrap justify-center gap-3">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={withdrawal.processing}
                                onClick={() => setReviewing(false)}
                            >
                                {t('Back')}
                            </Button>
                            <Button
                                disabled={!canReview || withdrawal.processing}
                                onClick={() => {
                                    withdrawal.transform((data) => ({
                                        ...data,
                                        address: normalizedAddress,
                                        confirmed: true,
                                        expected_fee: reviewedFee,
                                    }));
                                    withdrawal.post('/wallet/withdrawals', {
                                        onError: () => setReviewing(false),
                                        onSuccess: () =>
                                            withdrawal.reset('address', 'amount', 'confirmed'),
                                    });
                                }}
                            >
                                {withdrawal.processing ? t('Submitting…') : t('Confirm withdrawal')}
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </UserLayout>
    );
}
