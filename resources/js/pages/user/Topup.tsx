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

const compactAmount = (amount: MoneyAmount) => {
    const [integer, fraction = ''] = amount.split('.');
    const trimmed = fraction.replace(/0+$/, '');
    return trimmed ? `${integer}.${trimmed}` : integer;
};

export default function Topup({ available, wallet, topupAvailable, orders }: Props) {
    const [amount, setAmount] = useState('');
    const [reviewing, setReviewing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const requestId = useRef(crypto.randomUUID());
    const canContinue =
        /^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/.test(amount) && !/^0+(?:\.0+)?$/.test(amount);

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
            <Head title="Top up" />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title="Top up" backHref="/wallet" />
                {available ? (
                    <UserBalanceHero amount={available.amount} asset={available.asset} />
                ) : null}
                {!topupAvailable || !wallet ? (
                    <UserStatusBanner
                        tone="warning"
                        title="Top-up unavailable"
                        description="An active verified USDT wallet is required for TRC20 top-ups."
                    />
                ) : reviewing ? (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <p className="text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                            Review
                        </p>
                        <h2 className="mt-2 text-lg font-semibold">Create payment instructions</h2>
                        <dl className="mt-5 divide-y border-y">
                            <div className="flex justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Requested</dt>
                                <dd className="font-semibold">
                                    <MoneyDisplay amount={amount} asset="USDT" compact />
                                </dd>
                            </div>
                            <div className="flex justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Network</dt>
                                <dd className="font-semibold">TRC20</dd>
                            </div>
                        </dl>
                        <p className="mt-4 text-sm leading-6 text-muted-foreground">
                            A 0.01–0.99 identification amount will be added. Your wallet receives
                            the full exact amount sent; it is not a fee.
                        </p>
                        <div className="mt-5 grid gap-3 sm:flex sm:justify-end">
                            <Button variant="secondary" onClick={() => setReviewing(false)}>
                                Back
                            </Button>
                            <Button disabled={processing} onClick={submit}>
                                {processing ? 'Creating instructions…' : 'Continue'}
                            </Button>
                        </div>
                    </section>
                ) : (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <FormField label="Amount" id="topup-amount">
                            <div className="flex items-center gap-3">
                                <Input
                                    id="topup-amount"
                                    inputMode="decimal"
                                    autoComplete="off"
                                    placeholder="100.00"
                                    value={amount}
                                    onChange={(event) => setAmount(event.target.value)}
                                />
                                <span className="text-sm font-semibold">USDT</span>
                            </div>
                        </FormField>
                        <p className="mt-3 text-xs leading-5 text-muted-foreground">
                            TRON network (TRC20) · No top-up fee
                        </p>
                        <Button
                            className="mt-5 w-full"
                            disabled={!canContinue}
                            onClick={() => setReviewing(true)}
                        >
                            Continue
                        </Button>
                    </section>
                )}
                <UserSection title="Top-up history">
                    {orders.length === 0 ? (
                        <UserEmptyState
                            title="No top-ups yet"
                            description="Your top-up history will appear here."
                        />
                    ) : (
                        <div className="divide-y">
                            {orders.map((order) => (
                                <UserListRow
                                    key={order.id}
                                    href={`/wallet/top-ups/${order.id}/return`}
                                    title="USDT top up"
                                    description={`Requested ${compactAmount(order.requestedAmount)} · ${new Date(order.createdAt).toLocaleString()}`}
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
                                                {statusLabel(order.status)}
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
