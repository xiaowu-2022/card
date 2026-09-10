import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Clock3, XCircle } from 'lucide-react';
import { useEffect } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Props = {
    order: {
        id: string;
        amount: MoneyAmount;
        asset: string;
        status: 'PROCESSING' | 'COMPLETED' | 'FAILED' | 'CANCELLED' | 'EXPIRED';
        paymentReceived: boolean;
    };
};

export default function TopupStatus({ order }: Props) {
    useEffect(() => {
        if (order.status !== 'PROCESSING') return;
        const timer = window.setInterval(() => router.reload({ only: ['order'] }), 4000);
        return () => window.clearInterval(timer);
    }, [order.status]);
    const completed = order.status === 'COMPLETED';
    const failed = ['FAILED', 'CANCELLED', 'EXPIRED'].includes(order.status);
    const Icon = completed ? CheckCircle2 : failed ? XCircle : Clock3;
    return (
        <UserLayout>
            <Head title="Top-up status" />
            <div className="space-y-6">
                <UserPageHeader title="Top-up status" backHref="/wallet/top-up" />
                <section className="rounded-[var(--user-radius-lg)] border bg-surface p-6 text-center sm:p-10">
                    <Icon
                        className={`mx-auto size-12 ${completed ? 'text-success' : failed ? 'text-danger' : 'text-warning'}`}
                    />
                    <h1 className="mt-5 text-xl font-semibold">
                        {completed
                            ? 'Top-up complete'
                            : failed
                              ? "Payment wasn't completed"
                              : order.paymentReceived
                                ? 'Payment received'
                                : 'Top-up processing'}
                    </h1>
                    <p className="mt-2 text-sm text-muted-foreground">
                        {completed
                            ? 'The funds are now available in your wallet.'
                            : failed
                              ? 'No funds were added to your wallet.'
                              : order.paymentReceived
                                ? "We're adding the funds to your wallet."
                                : "We're confirming your payment."}
                    </p>
                    <p className="mt-5 text-lg font-semibold">
                        <MoneyDisplay amount={order.amount} asset={order.asset} compact />
                    </p>
                    <Button asChild className="mt-6 w-full sm:w-auto">
                        <Link href="/wallet">Back to wallet</Link>
                    </Button>
                </section>
            </div>
        </UserLayout>
    );
}
