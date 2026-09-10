import { Head, Link } from '@inertiajs/react';
import { CheckCircle2 } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Receipt = { ledgerEntryId: string; amount: MoneyAmount; asset: string };

export default function SecurityDepositSuccess({ receipt }: { receipt: Receipt }) {
    return (
        <UserLayout>
            <Head title="Security deposit complete" />
            <section className="mx-auto max-w-lg py-10 text-center sm:py-16">
                <span className="mx-auto grid size-14 place-items-center rounded-full bg-emerald-50 text-success">
                    <CheckCircle2 className="size-7" />
                </span>
                <h1 className="mt-5 text-2xl font-semibold">Security deposit complete</h1>
                <p className="mt-3 text-muted-foreground">
                    <MoneyDisplay amount={receipt.amount} asset={receipt.asset} compact /> deposited
                </p>
                <Button asChild className="mt-8 w-full sm:w-auto">
                    <Link href="/wallet">Back to wallet</Link>
                </Button>
            </section>
        </UserLayout>
    );
}
