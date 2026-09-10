import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, CreditCard, LoaderCircle, RefreshCw, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserEmptyState } from '@/components/user/UserEmptyState';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { UserLayout } from '@/layouts/UserLayout';
import type { SharedProps } from '@/types/global';

type Product = {
    id: string;
    name: string;
    cardType: string;
    cardCurrency: string;
    openingFee: string;
    minimumInitialLoad: string;
    minimumRequiredBalance: string;
    maxCardsPerUser: number;
    readyForSetup: boolean;
    guidance: string;
};
type Profile = Partial<{
    legalFirstName: string;
    legalLastName: string;
    dateOfBirth: string;
    nationalityCountryCode: string;
    residentialAddress: string;
    residentialCity: string;
    residentialState: string;
    residentialCountryCode: string;
    residentialPostalCode: string;
}>;
type Cardholder = {
    state:
        | 'setup'
        | 'submitting'
        | 'reviewing'
        | 'ready'
        | 'action_required'
        | 'not_available'
        | 'unknown';
    safeReason: string | null;
    submittedAt: string | null;
    syncedAt: string | null;
};
type IssueOrder = {
    id: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    state: 'creating' | 'unknown' | 'created' | 'failed';
    requestedAt: string;
};
type UserCard = {
    id: string;
    productName: string;
    maskedPan: string;
    last4: string;
    expiry: string | null;
    currency: string;
    balance: string | null;
};
type Props = {
    products: Product[];
    providerAvailable: boolean;
    kycApproved: boolean;
    availableBalance: string | null;
    walletAsset: string | null;
    profile: Profile;
    cardholder: Cardholder;
    issueOrders: IssueOrder[];
    cards: UserCard[];
    demo?: boolean;
};

function minorUnits(value: string): bigint | null {
    const match = /^(\d+)(?:\.(\d{0,8}))?$/.exec(value);
    if (!match) return null;
    const fraction = (match[2] ?? '').padEnd(8, '0');
    return BigInt(match[1]!) * 100000000n + BigInt(fraction || '0');
}

function moneyFromMinor(value: bigint): string {
    const integer = value / 100000000n;
    const fraction = (value % 100000000n).toString().padStart(8, '0');
    return `${integer}.${fraction}`;
}

function CardSetupForm({ profile, update }: { profile: Profile; update: boolean }) {
    const form = useForm({
        legal_first_name: profile.legalFirstName ?? '',
        legal_last_name: profile.legalLastName ?? '',
        date_of_birth: profile.dateOfBirth ?? '',
        nationality_country_code: profile.nationalityCountryCode ?? '',
        residential_address: profile.residentialAddress ?? '',
        residential_city: profile.residentialCity ?? '',
        residential_state: profile.residentialState ?? '',
        residential_country_code: profile.residentialCountryCode ?? '',
        residential_postal_code: profile.residentialPostalCode ?? '',
    });
    const fields = [
        ['legal_first_name', 'Legal first name', 'text'],
        ['legal_last_name', 'Legal last name', 'text'],
        ['date_of_birth', 'Date of birth', 'date'],
        ['nationality_country_code', 'Nationality country code', 'text'],
        ['residential_address', 'Residential address', 'text'],
        ['residential_city', 'City', 'text'],
        ['residential_state', 'State / province', 'text'],
        ['residential_country_code', 'Residential country code', 'text'],
        ['residential_postal_code', 'Postal code', 'text'],
    ] as const;

    return (
        <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
            <span className="grid size-11 place-items-center rounded-full bg-[var(--user-primary-soft)] text-[var(--user-primary-readable)]">
                <ShieldCheck className="size-5" />
            </span>
            <h2 className="mt-5 text-xl font-semibold">
                {update ? 'Update card setup' : 'Card setup'}
            </h2>
            <p className="mt-2 max-w-2xl text-sm leading-6 text-muted-foreground">
                We reuse your approved identity documents securely. Confirm the personal and
                residential details required by the card provider.
            </p>
            <form
                className="mt-6 grid gap-5 sm:grid-cols-2"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post('/cards/cardholder');
                }}
            >
                {fields.map(([name, label, type]) => (
                    <FormField key={name} id={name} label={label} error={form.errors[name]}>
                        <Input
                            id={name}
                            type={type}
                            value={form.data[name]}
                            maxLength={name.includes('country_code') ? 2 : undefined}
                            onChange={(event) =>
                                form.setData(
                                    name,
                                    name.includes('country_code')
                                        ? event.target.value.toUpperCase()
                                        : event.target.value,
                                )
                            }
                            autoComplete="off"
                        />
                    </FormField>
                ))}
                <div className="sm:col-span-2">
                    <Button type="submit" className="w-full sm:w-auto" disabled={form.processing}>
                        {form.processing ? 'Submitting…' : update ? 'Resubmit details' : 'Continue'}
                    </Button>
                </div>
            </form>
        </section>
    );
}

function ProductIssue({
    product,
    availableBalance,
}: {
    product: Product;
    availableBalance: string | null;
}) {
    const [reviewing, setReviewing] = useState(false);
    const form = useForm({
        request_id: crypto.randomUUID(),
        card_product_id: product.id,
        initial_load_amount: product.minimumInitialLoad.replace(/0+$/, '').replace(/\.$/, ''),
    });
    const calculation = useMemo(() => {
        const opening = minorUnits(product.openingFee);
        const initial = minorUnits(form.data.initial_load_amount);
        const available = availableBalance === null ? null : minorUnits(availableBalance);
        const minimum = minorUnits(product.minimumInitialLoad);
        if (opening === null || initial === null || minimum === null) return null;
        const total = opening + initial;
        return {
            total: moneyFromMinor(total),
            enough: available !== null && available >= total,
            meetsMinimum: initial >= minimum,
        };
    }, [
        availableBalance,
        form.data.initial_load_amount,
        product.minimumInitialLoad,
        product.openingFee,
    ]);
    const canSubmit =
        product.readyForSetup && Boolean(calculation?.enough && calculation.meetsMinimum);

    return (
        <Card className="overflow-hidden border-0 shadow-sm">
            <CardContent className="p-0">
                <div className="bg-slate-950 p-6 text-white sm:p-7">
                    <div className="flex items-start justify-between gap-4">
                        <div>
                            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-white/60">
                                {product.cardType}
                            </p>
                            <h2 className="mt-2 text-2xl font-semibold">{product.name}</h2>
                        </div>
                        <CreditCard className="size-7 text-white/75" />
                    </div>
                    <p className="mt-10 text-sm text-white/70">
                        Virtual card · balance in {product.cardCurrency}
                    </p>
                </div>
                <div className="space-y-5 p-5 sm:p-6">
                    <dl className="divide-y border-y text-sm">
                        <div className="flex justify-between gap-4 py-3">
                            <dt className="text-muted-foreground">Opening fee</dt>
                            <dd className="font-semibold">
                                <MoneyDisplay amount={product.openingFee} asset="USDT" compact />
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4 py-3">
                            <dt className="text-muted-foreground">Minimum initial balance</dt>
                            <dd className="font-semibold">
                                <MoneyDisplay
                                    amount={product.minimumInitialLoad}
                                    asset="USDT"
                                    compact
                                />
                            </dd>
                        </div>
                        <div className="flex justify-between gap-4 py-3">
                            <dt className="text-muted-foreground">Available Wallet balance</dt>
                            <dd className="font-semibold">
                                {availableBalance ? (
                                    <MoneyDisplay amount={availableBalance} asset="USDT" compact />
                                ) : (
                                    'Unavailable'
                                )}
                            </dd>
                        </div>
                    </dl>
                    <FormField
                        id={`initial-${product.id}`}
                        label="Initial card balance"
                        error={form.errors.initial_load_amount}
                        description={`The card receives the same amount in ${product.cardCurrency}.`}
                    >
                        <Input
                            id={`initial-${product.id}`}
                            inputMode="decimal"
                            value={form.data.initial_load_amount}
                            onChange={(event) =>
                                form.setData('initial_load_amount', event.target.value)
                            }
                        />
                    </FormField>
                    {calculation ? (
                        <div className="flex items-center justify-between rounded-xl bg-muted p-4 text-sm">
                            <span>Total from Wallet</span>
                            <strong>
                                <MoneyDisplay amount={calculation.total} asset="USDT" compact />
                            </strong>
                        </div>
                    ) : null}
                    {!calculation?.meetsMinimum ? (
                        <p className="text-sm text-destructive">
                            Initial balance must be at least {product.minimumInitialLoad} USDT.
                        </p>
                    ) : null}
                    {calculation && !calculation.enough ? (
                        <UserStatusBanner
                            tone="warning"
                            title={`You need ${calculation.total} USDT to open this card.`}
                            description={`Available: ${availableBalance ?? '0.00000000'} USDT`}
                        />
                    ) : null}
                    {calculation && !calculation.enough ? (
                        <Button asChild className="w-full">
                            <Link href="/wallet/top-up">Top up wallet</Link>
                        </Button>
                    ) : (
                        <Button
                            className="w-full"
                            disabled={!canSubmit || form.processing}
                            onClick={() => setReviewing(true)}
                        >
                            Open card
                        </Button>
                    )}
                </div>
            </CardContent>
            <AlertDialog open={reviewing} onOpenChange={setReviewing}>
                <AlertDialogContent>
                    <div className="space-y-2">
                        <AlertDialogTitle>Confirm card opening</AlertDialogTitle>
                        <AlertDialogDescription>
                            {product.openingFee} USDT opening fee and{' '}
                            {form.data.initial_load_amount} USDT initial balance will be reserved
                            separately while the provider creates your card. Do not create another
                            request while status is pending.
                        </AlertDialogDescription>
                    </div>
                    <div className="mt-6 flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <AlertDialogCancel className="inline-flex min-h-10 items-center justify-center rounded-lg border bg-surface px-4 text-sm font-semibold">
                            Cancel
                        </AlertDialogCancel>
                        <AlertDialogAction
                            className="inline-flex min-h-10 items-center justify-center rounded-lg bg-primary px-4 text-sm font-semibold text-primary-foreground"
                            onClick={() =>
                                form.post('/cards/issues', { onSuccess: () => setReviewing(false) })
                            }
                        >
                            Confirm and open
                        </AlertDialogAction>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
        </Card>
    );
}

export default function Cards(props: Props) {
    const { errors } = usePage<SharedProps & { errors: { form?: string } }>().props;
    const unresolved = props.issueOrders.find(
        (order) => order.state === 'creating' || order.state === 'unknown',
    );
    const canSetup = props.kycApproved && props.providerAvailable && !props.demo;
    const showSetup = ['setup', 'action_required'].includes(props.cardholder.state);

    return (
        <UserLayout>
            <Head title="Cards" />
            <div className="space-y-7 sm:space-y-9">
                <UserPageHeader title="Cards" backHref="/dashboard" />

                {props.cards.length > 0 ? (
                    <section>
                        <h2 className="mb-4 text-lg font-semibold">Your cards</h2>
                        <div className="grid gap-4 sm:grid-cols-2">
                            {props.cards.map((card) => (
                                <div
                                    key={card.id}
                                    className="rounded-[var(--user-radius-lg)] bg-slate-950 p-5 text-white shadow-sm sm:p-6"
                                >
                                    <div className="flex items-start justify-between">
                                        <p className="font-semibold">{card.productName}</p>
                                        <CreditCard className="size-5 text-white/70" />
                                    </div>
                                    <p className="mt-8 font-mono text-lg tracking-wider">
                                        {card.maskedPan}
                                    </p>
                                    <div className="mt-5 flex items-end justify-between gap-4 text-sm">
                                        <div>
                                            <p className="text-white/60">Balance</p>
                                            <p className="mt-1 text-lg font-semibold">
                                                {card.balance ? (
                                                    <MoneyDisplay
                                                        amount={card.balance}
                                                        asset={card.currency}
                                                        compact
                                                    />
                                                ) : (
                                                    'Pending sync'
                                                )}
                                            </p>
                                        </div>
                                        <div className="text-right">
                                            <p className="text-white/60">Expiry</p>
                                            <p className="mt-1">{card.expiry ?? '—'}</p>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </section>
                ) : null}

                {!props.providerAvailable ? (
                    <UserStatusBanner
                        tone="warning"
                        title="Card setup unavailable"
                        description="The card provider is not configured. No request or Wallet hold has been created."
                    />
                ) : null}
                {!props.kycApproved && !props.demo ? (
                    <UserStatusBanner
                        tone="warning"
                        title="Identity verification required"
                        description="Complete identity verification before starting card setup."
                    />
                ) : null}
                {props.demo ? (
                    <UserStatusBanner
                        tone="neutral"
                        title="Card setup preview"
                        description="Sign in to start the real Demo flow. This public preview cannot create a Cardholder or reserve funds."
                    />
                ) : null}

                {unresolved ? (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <LoaderCircle className="size-7 text-primary" />
                        <h2 className="mt-4 text-xl font-semibold">Creating your card</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            We're confirming the card status. Your reserved funds remain protected;
                            do not open another card.
                        </p>
                        <Button
                            variant="secondary"
                            className="mt-5 w-full sm:w-auto"
                            onClick={() => router.post(`/cards/issues/${unresolved.id}/sync`)}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Refresh status
                        </Button>
                    </section>
                ) : showSetup && canSetup ? (
                    <CardSetupForm
                        profile={props.profile}
                        update={props.cardholder.state === 'action_required'}
                    />
                ) : ['submitting', 'reviewing', 'unknown'].includes(props.cardholder.state) ? (
                    <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                        <LoaderCircle className="size-7 text-primary" />
                        <h2 className="mt-4 text-xl font-semibold">Card setup submitted</h2>
                        <p className="mt-2 text-sm text-muted-foreground">
                            {props.cardholder.state === 'unknown'
                                ? 'The latest provider result is not confirmed. Refresh status safely before continuing.'
                                : 'Your cardholder information is being reviewed.'}
                        </p>
                        {props.cardholder.safeReason ? (
                            <p className="mt-3 text-sm">{props.cardholder.safeReason}</p>
                        ) : null}
                        <Button
                            variant="secondary"
                            className="mt-5 w-full sm:w-auto"
                            disabled={
                                props.cardholder.state === 'unknown' &&
                                props.cardholder.syncedAt === null
                            }
                            onClick={() => router.post('/cards/cardholder/sync')}
                        >
                            <RefreshCw className="mr-2 size-4" />
                            Refresh status
                        </Button>
                    </section>
                ) : props.cardholder.state === 'not_available' ? (
                    <UserStatusBanner
                        tone="warning"
                        title="Card setup is not available"
                        description={
                            props.cardholder.safeReason ??
                            'The card provider could not approve this setup.'
                        }
                    />
                ) : null}

                {errors.form ? <p className="text-sm text-destructive">{errors.form}</p> : null}

                {props.cardholder.state === 'ready' && !unresolved ? (
                    <section>
                        <div className="mb-4 flex items-center gap-2">
                            <CheckCircle2 className="size-5 text-emerald-600" />
                            <h2 className="text-lg font-semibold">Available cards</h2>
                        </div>
                        {props.products.length ? (
                            <div className="grid gap-5 xl:grid-cols-2">
                                {props.products.map((product) => (
                                    <ProductIssue
                                        key={product.id}
                                        product={product}
                                        availableBalance={props.availableBalance}
                                    />
                                ))}
                            </div>
                        ) : (
                            <UserEmptyState
                                title="No card products available"
                                description="Your card program is not currently accepting new requests."
                            />
                        )}
                    </section>
                ) : null}
            </div>
        </UserLayout>
    );
}
