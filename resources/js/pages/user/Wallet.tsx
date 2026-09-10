import { Head, Link, router } from '@inertiajs/react';
import { CheckCircle2, Plus, ShieldCheck } from 'lucide-react';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { UserActivityList } from '@/components/user/UserActivityList';
import { UserBalanceHero } from '@/components/user/UserBalanceHero';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserSection } from '@/components/user/UserSection';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserQuickActions } from '@/components/user/UserQuickActions';
import { Button } from '@/components/ui/button';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type Money = { amount: MoneyAmount; asset: string };
type Eligibility = {
    userStatus: string;
    tenantStatus: string;
    kycStatus: string;
    walletStatus: string | null;
    canActivate: boolean;
    depositSatisfied: boolean;
    reasonCodes: string[];
    wallet: { id: string; asset: string; status: string } | null;
    available: Money | null;
    depositCurrent: Money;
    depositRequired: Money;
    depositRemaining: Money;
};
type Props = {
    eligibility: Eligibility;
    activity: Array<{
        id: string;
        eventType: string;
        asset: string;
        amount?: MoneyAmount;
        postedAt: string;
    }>;
    topupAvailable?: boolean;
    depositFundingAvailable?: boolean;
    depositHasEnoughAvailable?: boolean;
};

function InactiveWallet({ eligibility }: { eligibility: Eligibility }) {
    if (eligibility.kycStatus === 'APPROVED' && eligibility.canActivate) {
        return (
            <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                <span className="grid size-11 place-items-center rounded-full bg-emerald-50 text-success">
                    <CheckCircle2 className="size-5" />
                </span>
                <h2 className="mt-5 text-xl font-semibold">Your identity is verified</h2>
                <p className="mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                    Activate your wallet to continue. Activation does not move funds.
                </p>
                <Button
                    className="mt-5 w-full sm:w-auto"
                    onClick={() => router.post('/wallet/activate')}
                >
                    Activate wallet
                </Button>
            </section>
        );
    }

    const copy: Record<string, string> = {
        NOT_SUBMITTED: 'Verify your identity before activating a wallet.',
        PENDING: 'Your identity verification is under review.',
        REJECTED: 'Identity verification is unavailable. Contact support for assistance.',
        RESUBMISSION_REQUIRED: 'Updated identity documents are required before you can continue.',
    };
    const canVisitKyc =
        eligibility.kycStatus === 'NOT_SUBMITTED' ||
        eligibility.kycStatus === 'RESUBMISSION_REQUIRED';
    return (
        <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
            <span className="grid size-11 place-items-center rounded-full bg-muted text-muted-foreground">
                <ShieldCheck className="size-5" />
            </span>
            <h2 className="mt-5 text-xl font-semibold">Wallet is not activated</h2>
            <p className="mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                {copy[eligibility.kycStatus] ?? 'Wallet activation is currently unavailable.'}
            </p>
            {canVisitKyc ? (
                <Button asChild variant="secondary" className="mt-5 w-full sm:w-auto">
                    <Link href="/kyc">Identity verification</Link>
                </Button>
            ) : null}
        </section>
    );
}

export default function Wallet({
    eligibility,
    activity,
    topupAvailable = false,
    depositFundingAvailable = false,
    depositHasEnoughAvailable = false,
}: Props) {
    const activated = eligibility.wallet !== null && eligibility.available !== null;
    const activityItems = activity.map((entry) => ({
        id: entry.id,
        title:
            entry.eventType === 'WALLET_TOPUP_CREDIT'
                ? 'Wallet top up'
                : entry.eventType === 'SECURITY_DEPOSIT_FUND'
                  ? 'Security deposit'
                  : 'Wallet activity',
        postedAt: entry.postedAt,
        asset: entry.asset,
        amount: entry.amount,
        direction:
            entry.eventType === 'WALLET_TOPUP_CREDIT'
                ? ('CREDIT' as const)
                : entry.eventType === 'SECURITY_DEPOSIT_FUND'
                  ? ('DEBIT' as const)
                  : ('NEUTRAL' as const),
    }));

    return (
        <UserLayout>
            <Head title="Wallet" />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title="Wallet" backHref="/dashboard" />
                {!activated ? (
                    <InactiveWallet eligibility={eligibility} />
                ) : (
                    <>
                        {eligibility.userStatus !== 'ACTIVE' ||
                        eligibility.tenantStatus !== 'ACTIVE' ? (
                            <UserStatusBanner
                                tone="warning"
                                title="Wallet access is restricted"
                                description="You can review your balance, but financial actions are unavailable."
                            />
                        ) : null}
                        <UserBalanceHero
                            amount={eligibility.available!.amount}
                            asset={eligibility.available!.asset}
                        />
                        {topupAvailable ? (
                            <UserQuickActions
                                actions={[{ label: 'Top up', href: '/wallet/top-up', icon: Plus }]}
                            />
                        ) : null}
                        <UserSection title="Security deposit">
                            <div className="rounded-[var(--user-radius-md)] border bg-surface px-5 py-5">
                                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span className="text-2xl font-semibold">
                                        <MoneyDisplay {...eligibility.depositCurrent} compact />
                                    </span>
                                    <span className="text-sm text-muted-foreground">
                                        of <MoneyDisplay {...eligibility.depositRequired} compact />{' '}
                                        required
                                    </span>
                                </div>
                                <p className="mt-3 text-sm font-medium text-muted-foreground">
                                    {eligibility.depositSatisfied ? (
                                        'Requirement met'
                                    ) : (
                                        <>
                                            <MoneyDisplay
                                                {...eligibility.depositRemaining}
                                                compact
                                            />{' '}
                                            remaining
                                        </>
                                    )}
                                </p>
                                {depositFundingAvailable ? (
                                    <Button asChild className="mt-5 w-full sm:w-auto">
                                        <Link
                                            href={
                                                depositHasEnoughAvailable
                                                    ? '/security-deposit'
                                                    : '/wallet/top-up'
                                            }
                                        >
                                            {depositHasEnoughAvailable
                                                ? 'Pay security deposit'
                                                : 'Top up wallet'}
                                        </Link>
                                    </Button>
                                ) : null}
                            </div>
                        </UserSection>
                        <UserSection title="Recent activity">
                            <UserActivityList items={activityItems} />
                        </UserSection>
                    </>
                )}
            </div>
        </UserLayout>
    );
}
