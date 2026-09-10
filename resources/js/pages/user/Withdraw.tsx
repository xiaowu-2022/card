import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserSection } from '@/components/user/UserSection';
import { Button } from '@/components/ui/button';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Destination = { id: string; maskedAddress: string; label: string | null };

function subtractDecimal(left: string, right: string): string {
    if (!/^\d+(?:\.\d{1,8})?$/.test(left) || !/^\d+(?:\.\d{1,8})?$/.test(right)) return '—';

    const scaled = (value: string) => {
        const [whole, fraction = ''] = value.split('.');
        return BigInt(`${whole}${fraction.padEnd(8, '0')}`);
    };
    const result = scaled(left) - scaled(right);
    if (result < 0n) return '0.00000000';
    const digits = result.toString().padStart(9, '0');
    return `${digits.slice(0, -8)}.${digits.slice(-8)}`;
}

export default function Withdraw({
    available,
    network,
    destinations,
}: {
    available: { amount: MoneyAmount; asset: string };
    network: string;
    destinations: Destination[];
}) {
    const [reviewing, setReviewing] = useState(false);
    const address = useForm({ address: '', label: '' });
    const withdrawal = useForm<{
        request_id: string;
        destination_id: string;
        amount: string;
        form?: string;
    }>({
        request_id: crypto.randomUUID(),
        destination_id: destinations[0]?.id ?? '',
        amount: '',
        form: undefined,
    });
    const selected = destinations.find(
        (destination) => destination.id === withdrawal.data.destination_id,
    );
    const availableAfter = reviewing
        ? subtractDecimal(available.amount, withdrawal.data.amount)
        : available.amount;

    return (
        <UserLayout>
            <Head title="Withdraw USDT" />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader
                    title="Withdraw"
                    backHref="/wallet"
                    description="USDT withdrawals use the TRON network."
                />
                {!reviewing ? (
                    <>
                        <div>
                            <p className="text-sm text-muted-foreground">Available</p>
                            <p className="mt-1 text-3xl font-semibold">
                                <MoneyDisplay {...available} compact />
                            </p>
                        </div>
                        {destinations.length === 0 ? (
                            <UserSection title="Add withdrawal address">
                                <form
                                    className="space-y-4"
                                    onSubmit={(event) => {
                                        event.preventDefault();
                                        address.post('/wallet/withdrawal-destinations');
                                    }}
                                >
                                    <FormField
                                        id="tron-address"
                                        label="TRON address"
                                        error={address.errors.address}
                                    >
                                        <Input
                                            id="tron-address"
                                            value={address.data.address}
                                            onChange={(event) =>
                                                address.setData('address', event.target.value)
                                            }
                                            placeholder="T..."
                                            autoComplete="off"
                                        />
                                    </FormField>
                                    <FormField
                                        id="address-label"
                                        label="Label (optional)"
                                        error={address.errors.label}
                                    >
                                        <Input
                                            id="address-label"
                                            value={address.data.label}
                                            onChange={(event) =>
                                                address.setData('label', event.target.value)
                                            }
                                            placeholder="My wallet"
                                        />
                                    </FormField>
                                    <div className="rounded-lg bg-muted px-4 py-3 text-sm">
                                        <span className="text-muted-foreground">Network</span>
                                        <span className="float-right font-medium">
                                            USDT ({network})
                                        </span>
                                    </div>
                                    <Button
                                        className="w-full sm:w-auto"
                                        disabled={address.processing}
                                    >
                                        Add address
                                    </Button>
                                </form>
                            </UserSection>
                        ) : (
                            <form
                                className="space-y-5"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    setReviewing(true);
                                }}
                            >
                                <FormField
                                    id="withdrawal-amount"
                                    label="Amount"
                                    error={withdrawal.errors.amount}
                                >
                                    <Input
                                        id="withdrawal-amount"
                                        inputMode="decimal"
                                        value={withdrawal.data.amount}
                                        onChange={(event) =>
                                            withdrawal.setData('amount', event.target.value)
                                        }
                                        placeholder="100.00"
                                    />
                                </FormField>
                                <FormField
                                    id="withdrawal-address"
                                    label="Withdrawal address"
                                    error={withdrawal.errors.destination_id}
                                >
                                    <Select
                                        value={withdrawal.data.destination_id}
                                        onValueChange={(value) =>
                                            withdrawal.setData('destination_id', value)
                                        }
                                    >
                                        <SelectTrigger id="withdrawal-address">
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {destinations.map((destination) => (
                                                <SelectItem
                                                    key={destination.id}
                                                    value={destination.id}
                                                >
                                                    {destination.label
                                                        ? `${destination.label} — `
                                                        : ''}
                                                    {destination.maskedAddress}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FormField>
                                <div className="rounded-lg bg-muted px-4 py-3 text-sm">
                                    <span className="text-muted-foreground">Network</span>
                                    <span className="float-right font-medium">
                                        USDT ({network})
                                    </span>
                                </div>
                                {withdrawal.errors.form ? (
                                    <p className="text-sm text-destructive">
                                        {withdrawal.errors.form}
                                    </p>
                                ) : null}
                                <Button
                                    className="w-full sm:w-auto"
                                    disabled={
                                        !withdrawal.data.amount || !withdrawal.data.destination_id
                                    }
                                >
                                    Continue
                                </Button>
                            </form>
                        )}
                    </>
                ) : (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <p className="text-sm text-muted-foreground">You are withdrawing</p>
                        <p className="mt-2 text-3xl font-semibold">{withdrawal.data.amount} USDT</p>
                        <dl className="mt-6 divide-y border-y text-sm">
                            <div className="flex justify-between gap-4 py-4">
                                <dt className="text-muted-foreground">Network</dt>
                                <dd className="font-medium">TRC20</dd>
                            </div>
                            <div className="flex justify-between gap-4 py-4">
                                <dt className="text-muted-foreground">Address</dt>
                                <dd className="font-medium">{selected?.maskedAddress}</dd>
                            </div>
                            <div className="flex justify-between gap-4 py-4">
                                <dt className="text-muted-foreground">Available after</dt>
                                <dd className="font-medium">{availableAfter} USDT</dd>
                            </div>
                        </dl>
                        {withdrawal.errors.form ? (
                            <p className="mt-4 text-sm text-destructive">
                                {withdrawal.errors.form}
                            </p>
                        ) : null}
                        <div className="mt-6 flex flex-col-reverse gap-3 sm:flex-row">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => setReviewing(false)}
                            >
                                Back
                            </Button>
                            <Button
                                disabled={withdrawal.processing}
                                onClick={() => withdrawal.post('/wallet/withdrawals')}
                            >
                                {withdrawal.processing ? 'Submitting…' : 'Confirm withdrawal'}
                            </Button>
                        </div>
                    </section>
                )}
            </div>
        </UserLayout>
    );
}
