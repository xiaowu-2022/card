import { meetsTopupMinimum, exactAmount } from '@/lib/exact-amount';
import { t, useClientTranslation, dateTime } from '@/i18n';
import { Head, router } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserBalanceHero } from '@/components/user/UserBalanceHero';
import { UserEmptyState } from '@/components/user/UserEmptyState';
import { UserListRow } from '@/components/user/UserListRow';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserSection } from '@/components/user/UserSection';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type OrderStatus =
    | 'WAITING'
    | 'CONFIRMING'
    | 'ADDING_FUNDS'
    | 'PROCESSING'
    | 'COMPLETED'
    | 'FAILED'
    | 'CANCELLED'
    | 'EXPIRED';
type Order = {
    id: string;
    reference: string;
    requestedAmount: MoneyAmount;
    expectedAmount: MoneyAmount;
    asset: string;
    status: OrderStatus;
    createdAt: string;
};
type Props = {
    available: { amount: MoneyAmount; asset: string } | null;
    wallet: { id: string; asset: string } | null;
    topupAvailable: boolean;
    minimum: string;
    orders: Order[];
};

const statusLabel = (status: OrderStatus) =>
    ({
        WAITING: 'Waiting for payment',
        CONFIRMING: 'Confirming transaction',
        ADDING_FUNDS: 'Adding funds to wallet',
        PROCESSING: 'Processing',
        COMPLETED: 'Top-up complete',
        FAILED: 'Failed',
        CANCELLED: 'Cancelled',
        EXPIRED: 'Top-up expired',
    })[status];

export default function Topup({ available, wallet, topupAvailable, orders, minimum }: Props) {
    useClientTranslation();
    const [amount, setAmount] = useState('');
    const [processing, setProcessing] = useState(false);
    const requestId = useRef(crypto.randomUUID());
    const canContinue = meetsTopupMinimum(amount, minimum);

    const submit = () => {
        if (!wallet || !canContinue || processing) return;
        setProcessing(true);
        router.post(
            '/wallet/top-ups',
            { request_id: requestId.current, requested_amount: amount },
            { onFinish: () => setProcessing(false) },
        );
    };

    return (
        <UserLayout>
            <Head title={t('Top up')} />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title={t('Top up')} backHref="/dashboard" />
                {available ? (
                    <UserBalanceHero
                        amount={available.amount}
                        asset={available.asset}
                        assetLabel="USDT"
                    />
                ) : null}
                {!topupAvailable || !wallet ? (
                    <UserStatusBanner
                        tone="warning"
                        title={t('Top-up unavailable')}
                        description={t(
                            'An active verified USDT wallet is required for TRC20 top-ups.',
                        )}
                    />
                ) : (
                    <form
                        className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7"
                        onSubmit={(event) => {
                            event.preventDefault();
                            submit();
                        }}
                    >
                        <FormField label={t('Amount')} id="topup-amount">
                            <div className="flex items-center gap-3">
                                <Input
                                    id="topup-amount"
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder="100.00"
                                    value={amount}
                                    disabled={processing}
                                    onChange={(event) => setAmount(event.target.value)}
                                />
                                <span className="text-sm font-semibold">USDT</span>
                            </div>
                        </FormField>
                        <p className="mt-3 text-xs leading-5 text-muted-foreground">
                            {t('Minimum deposit')}: {exactAmount(minimum)} {'USDT'} ·{' '}
                            {t('TRON network (TRC20) · No top-up fee')}
                        </p>
                        <Button className="mt-5 w-full" disabled={!canContinue || processing}>
                            {processing ? t('Creating instructions…') : t('Continue')}
                        </Button>
                    </form>
                )}
                <UserSection title={t('Top-up history')}>
                    {orders.length === 0 ? (
                        <UserEmptyState
                            title={t('No top-ups yet')}
                            description={t('Your top-up history will appear here.')}
                        />
                    ) : (
                        <div className="divide-y">
                            {orders.map((order) => (
                                <UserListRow
                                    key={order.id}
                                    href={`/wallet/top-ups/${order.id}/return`}
                                    title={t('USDT top up')}
                                    description={dateTime(order.createdAt)}
                                    value={
                                        <span className="text-right">
                                            <span className="block font-semibold">
                                                <MoneyDisplay
                                                    amount={order.expectedAmount}
                                                    asset={order.asset}
                                                    compact
                                                />
                                            </span>
                                            <span className="text-xs text-muted-foreground">
                                                {t(statusLabel(order.status))}
                                            </span>
                                        </span>
                                    }
                                />
                            ))}
                        </div>
                    )}
                </UserSection>
            </div>
        </UserLayout>
    );
}
