import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ShieldCheck } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount, SharedProps } from '@/types/global';

type Money = { amount: MoneyAmount; asset: string };
type Preview = {
    current: Money;
    required: Money;
    remaining: Money;
    available: Money;
    availableAfter: Money | null;
    canFund: boolean;
    satisfied: boolean;
    topupAvailable: boolean;
};

export default function SecurityDeposit({ preview }: { preview: Preview }) {
    const form = useForm({
        request_id: crypto.randomUUID(),
        expected_remaining: preview.remaining.amount,
    });
    const formError = usePage<SharedProps & { errors: { form?: string } }>().props.errors.form;

    return (
        <UserLayout>
            <Head title="Security deposit" />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title="Security deposit" backHref="/wallet" />
                {preview.satisfied ? (
                    <UserStatusBanner
                        tone="success"
                        title="Requirement met"
                        description="Your security deposit requirement is complete."
                    />
                ) : !preview.canFund ? (
                    <>
                        <UserStatusBanner
                            tone="warning"
                            title={`You need ${preview.remaining.amount} ${preview.remaining.asset} to complete your security deposit`}
                            description={`Available balance: ${preview.available.amount} ${preview.available.asset}`}
                        />
                        {preview.topupAvailable ? (
                            <Button asChild className="w-full sm:w-auto">
                                <Link href="/wallet/top-up">Top up wallet</Link>
                            </Button>
                        ) : null}
                    </>
                ) : (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <span className="grid size-11 place-items-center rounded-full bg-[var(--user-primary-soft)] text-[var(--user-primary-readable)]">
                            <ShieldCheck className="size-5" />
                        </span>
                        <h2 className="mt-5 text-xl font-semibold">Review deposit</h2>
                        <p className="mt-2 text-sm leading-6 text-muted-foreground">
                            Funds will be held separately from your available balance.
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
                                    <dt className="text-muted-foreground">{label as string}</dt>
                                    <dd className="font-semibold">
                                        <MoneyDisplay {...(money as Money)} compact />
                                    </dd>
                                </div>
                            ))}
                        </dl>
                        {formError ? (
                            <p className="mt-4 text-sm text-destructive">{formError}</p>
                        ) : null}
                        <Button
                            className="mt-6 w-full sm:w-auto"
                            disabled={form.processing}
                            onClick={() => form.post('/security-deposit/fund')}
                        >
                            {form.processing ? 'Confirming…' : 'Confirm deposit'}
                        </Button>
                    </section>
                )}
            </div>
        </UserLayout>
    );
}
