import { Head, router } from '@inertiajs/react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

export default function MockPayment({
    payment,
}: {
    payment: { providerRequestId: string; amount: MoneyAmount; asset: string };
}) {
    return (
        <UserLayout>
            <Head title="Mock payment" />
            <div className="space-y-6">
                <UserPageHeader title="TEST / MOCK payment" backHref="/wallet/top-up" />
                <section className="rounded-[var(--user-radius-lg)] border border-warning/40 bg-surface p-6">
                    <p className="text-sm text-muted-foreground">
                        Local development simulation only. No real payment details are collected.
                    </p>
                    <p className="mt-5 text-2xl font-semibold">
                        <MoneyDisplay amount={payment.amount} asset={payment.asset} compact />
                    </p>
                    <Button
                        className="mt-6 w-full"
                        onClick={() =>
                            router.post(`/__mock/payments/${payment.providerRequestId}/complete`)
                        }
                    >
                        Simulate successful payment
                    </Button>
                </section>
            </div>
        </UserLayout>
    );
}
