import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
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

type Order = {
    id: string;
    reference: string;
    amount: MoneyAmount;
    asset: string;
    status: 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'CANCELLED' | 'EXPIRED';
    createdAt: string;
};
type Props = {
    available: { amount: MoneyAmount; asset: string } | null;
    wallet: { id: string; asset: string } | null;
    topupAvailable: boolean;
    orders: Order[];
};

const statusLabel = (status: Order['status']) =>
    ({
        PROCESSING: 'Processing',
        COMPLETED: 'Completed',
        FAILED: 'Failed',
        CANCELLED: 'Cancelled',
        EXPIRED: 'Expired',
    })[status];

export default function Topup({ available, wallet, topupAvailable, orders }: Props) {
    const [amount, setAmount] = useState('');
    const [reviewing, setReviewing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const canContinue = /^\d+(?:\.\d{1,8})?$/.test(amount) && !/^0+(?:\.0+)?$/.test(amount);

    const submit = () => {
        if (!wallet || !canContinue) return;
        setProcessing(true);
        router.post(
            '/wallet/top-ups',
            { request_id: crypto.randomUUID(), wallet_id: wallet.id, amount, asset: wallet.asset },
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
                        description="Top-up is not available for this account right now."
                    />
                ) : reviewing ? (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <h2 className="text-lg font-semibold">Review your top-up</h2>
                        <dl className="mt-5 divide-y border-y">
                            <div className="flex justify-between py-4 text-sm">
                                <dt className="text-muted-foreground">Amount</dt>
                                <dd className="font-semibold">
                                    <MoneyDisplay amount={amount} asset={wallet.asset} compact />
                                </dd>
                            </div>
                            <div className="flex justify-between py-4 text-sm">
                                <dt className="text-muted-foreground">You'll receive</dt>
                                <dd className="font-semibold">
                                    <MoneyDisplay amount={amount} asset={wallet.asset} compact />
                                </dd>
                            </div>
                        </dl>
                        <div className="mt-5 grid gap-3 sm:flex sm:justify-end">
                            <Button variant="secondary" onClick={() => setReviewing(false)}>
                                Back
                            </Button>
                            <Button disabled={processing} onClick={submit}>
                                {processing ? 'Opening payment…' : 'Continue to payment'}
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
                                <span className="text-sm font-semibold">{wallet.asset}</span>
                            </div>
                        </FormField>
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
                                    title="Wallet top up"
                                    description={`${order.reference} · ${new Date(order.createdAt).toLocaleString()}`}
                                    value={
                                        <span className="text-right">
                                            <span className="block font-semibold">
                                                <MoneyDisplay
                                                    amount={order.amount}
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
