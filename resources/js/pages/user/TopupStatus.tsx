import { Head, Link, router } from '@inertiajs/react';
import { Check, CheckCircle2, Clock3, Copy, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Status =
    | 'WAITING'
    | 'CONFIRMING'
    | 'ADDING_FUNDS'
    | 'PROCESSING'
    | 'COMPLETED'
    | 'FAILED'
    | 'CANCELLED'
    | 'EXPIRED';
type Props = {
    order: {
        id: string;
        requestedAmount: MoneyAmount;
        expectedAmount: MoneyAmount;
        amount: MoneyAmount;
        asset: string;
        status: Status;
        paymentDetected: boolean;
        network: string | null;
        depositAddress: string | null;
        expiresAt: string | null;
        mockSimulationAvailable: boolean;
    };
};

function remaining(expiresAt: string | null): string {
    if (!expiresAt) return '';
    const seconds = Math.max(0, Math.floor((new Date(expiresAt).getTime() - Date.now()) / 1000));
    return `${String(Math.floor(seconds / 60)).padStart(2, '0')}:${String(seconds % 60).padStart(2, '0')}`;
}

export default function TopupStatus({ order }: Props) {
    const [countdown, setCountdown] = useState(() => remaining(order.expiresAt));
    const [simulating, setSimulating] = useState(false);
    const processing = ['WAITING', 'CONFIRMING', 'ADDING_FUNDS', 'PROCESSING'].includes(
        order.status,
    );
    useEffect(() => {
        if (!processing) return;
        const timer = window.setInterval(() => {
            setCountdown(remaining(order.expiresAt));
            router.reload({ only: ['order'] });
        }, 4000);
        return () => window.clearInterval(timer);
    }, [order.expiresAt, processing]);

    const copy = async (value: string, label: string) => {
        await navigator.clipboard.writeText(value);
        toast.success(`${label} copied`);
    };
    const completed = order.status === 'COMPLETED';
    const expired = order.status === 'EXPIRED';
    const failed = ['FAILED', 'CANCELLED'].includes(order.status);
    const trc20 = order.network === 'TRON' && order.depositAddress;

    if (trc20 && !completed && !expired && !failed) {
        return (
            <UserLayout>
                <Head title="Send USDT" />
                <div className="space-y-5 sm:space-y-6">
                    <UserPageHeader title="Send USDT" backHref="/wallet/top-up" />
                    <UserStatusBanner
                        tone={order.paymentDetected ? 'pending' : 'warning'}
                        title={order.paymentDetected ? 'Payment detected' : 'Waiting for payment'}
                        description={
                            order.paymentDetected
                                ? 'Confirming transaction. Keep this instruction open while the network confirms it.'
                                : 'Send the exact amount displayed before the instruction expires.'
                        }
                    />
                    <section className="overflow-hidden rounded-[var(--user-radius-lg)] border bg-surface">
                        <div className="border-b p-5 sm:p-7">
                            <p className="text-sm text-muted-foreground">Amount to send</p>
                            <p className="mt-2 text-3xl font-semibold tracking-tight">
                                <MoneyDisplay amount={order.expectedAmount} asset="USDT" compact />
                            </p>
                            <p className="mt-2 text-sm font-medium text-danger">
                                Send this exact amount. A different amount cannot be credited
                                automatically.
                            </p>
                        </div>
                        <dl className="divide-y px-5 sm:px-7">
                            <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Network</dt>
                                <dd className="font-semibold">TRC20</dd>
                            </div>
                            <div className="py-4 text-sm">
                                <dt className="text-muted-foreground">Shared deposit address</dt>
                                <dd className="mt-2 flex min-w-0 items-center gap-2">
                                    <code className="min-w-0 flex-1 break-all text-sm font-semibold">
                                        {order.depositAddress}
                                    </code>
                                    <Button
                                        size="icon"
                                        variant="secondary"
                                        aria-label="Copy address"
                                        onClick={() => void copy(order.depositAddress!, 'Address')}
                                    >
                                        <Copy className="size-4" />
                                    </Button>
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Requested</dt>
                                <dd className="font-medium">
                                    <MoneyDisplay
                                        amount={order.requestedAmount}
                                        asset="USDT"
                                        compact
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Wallet receives</dt>
                                <dd className="font-semibold">
                                    <MoneyDisplay
                                        amount={order.expectedAmount}
                                        asset="USDT"
                                        compact
                                    />
                                </dd>
                            </div>
                            <div className="flex items-center justify-between gap-4 py-4 text-sm">
                                <dt className="text-muted-foreground">Expires in</dt>
                                <dd className="text-right font-mono font-semibold">
                                    {order.paymentDetected
                                        ? 'Reserved while confirming'
                                        : countdown}
                                </dd>
                            </div>
                        </dl>
                        <div className="grid gap-3 border-t p-5 sm:grid-cols-2 sm:p-7">
                            <Button
                                variant="secondary"
                                onClick={() => void copy(order.expectedAmount, 'Amount')}
                            >
                                <Copy className="mr-2 size-4" />
                                Copy amount
                            </Button>
                            {order.mockSimulationAvailable ? (
                                <Button
                                    disabled={simulating}
                                    onClick={() => {
                                        setSimulating(true);
                                        router.post(
                                            `/__mock/topups/${order.id}/complete`,
                                            {},
                                            {
                                                preserveScroll: true,
                                                onFinish: () => setSimulating(false),
                                            },
                                        );
                                    }}
                                >
                                    <Check className="mr-2 size-4" />
                                    {simulating ? 'Simulating…' : 'Simulate demo payment'}
                                </Button>
                            ) : null}
                        </div>
                    </section>
                </div>
            </UserLayout>
        );
    }

    const Icon = completed ? CheckCircle2 : failed || expired ? XCircle : Clock3;
    return (
        <UserLayout>
            <Head title="Top-up status" />
            <div className="space-y-6">
                <UserPageHeader title="Top-up status" backHref="/wallet/top-up" />
                <section className="rounded-[var(--user-radius-lg)] border bg-surface p-6 text-center sm:p-10">
                    <Icon
                        className={`mx-auto size-12 ${completed ? 'text-success' : failed || expired ? 'text-danger' : 'text-warning'}`}
                    />
                    <h1 className="mt-5 text-xl font-semibold">
                        {completed
                            ? 'Top-up complete'
                            : expired
                              ? 'Top-up expired'
                              : failed
                                ? "Payment wasn't completed"
                                : 'Adding funds to wallet'}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {completed
                            ? 'The full exact transfer is now available in your wallet.'
                            : expired
                              ? 'Create a new top-up to continue. No new amount was generated automatically.'
                              : failed
                                ? 'No funds were added to your wallet.'
                                : 'Your payment is confirmed and the wallet credit is being completed.'}
                    </p>
                    <p className="mt-5 text-lg font-semibold">
                        <MoneyDisplay
                            amount={order.expectedAmount ?? order.amount}
                            asset={order.asset}
                            compact
                        />
                    </p>
                    <Button asChild className="mt-6 w-full sm:w-auto">
                        <Link href={expired ? '/wallet/top-up' : '/wallet'}>
                            {expired ? 'Create new top-up' : 'Back to wallet'}
                        </Link>
                    </Button>
                </section>
            </div>
        </UserLayout>
    );
}
