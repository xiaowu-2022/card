import { Head } from '@inertiajs/react';
import { UserBalanceHero } from '@/components/user/UserBalanceHero';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type KycStatus = 'NOT_SUBMITTED' | 'PENDING' | 'APPROVED' | 'REJECTED' | 'RESUBMISSION_REQUIRED';
type WalletState = {
    status: string;
    available: { amount: MoneyAmount; asset: string };
    depositSatisfied: boolean;
    depositRemaining: { amount: MoneyAmount; asset: string };
    depositHasEnoughAvailable: boolean;
    topupAvailable: boolean;
};
type Props = {
    account: { displayName: string | null; status: string; verifiedChannel: string } | null;
    kycStatus: KycStatus;
    wallet: WalletState | null;
};

function NextStep({ kycStatus, wallet }: { kycStatus: KycStatus; wallet: WalletState | null }) {
    if (kycStatus === 'NOT_SUBMITTED') {
        return (
            <UserStatusBanner
                title="Complete identity verification"
                description="Verify your identity before using financial services."
                action={{ label: 'Verify now', href: '/kyc' }}
            />
        );
    }
    if (kycStatus === 'PENDING') {
        return (
            <UserStatusBanner
                tone="pending"
                title="Verification under review"
                description="Your information has been submitted. We will let you know when review is complete."
            />
        );
    }
    if (kycStatus === 'RESUBMISSION_REQUIRED') {
        return (
            <UserStatusBanner
                tone="warning"
                title="Action required"
                description="We need updated identity documents before you can continue."
                action={{ label: 'Review request', href: '/kyc' }}
            />
        );
    }
    if (kycStatus === 'REJECTED') {
        return (
            <UserStatusBanner
                tone="warning"
                title="Verification unavailable"
                description="We could not verify your identity. Contact support for assistance."
            />
        );
    }
    if (!wallet) {
        return (
            <UserStatusBanner
                tone="success"
                title="Your identity is verified"
                description="Activate your wallet to continue."
                action={{ label: 'Activate wallet', href: '/wallet' }}
            />
        );
    }
    if (!wallet.depositSatisfied) {
        const canPay = wallet.depositHasEnoughAvailable;
        return (
            <UserStatusBanner
                tone="warning"
                title="Complete your security deposit"
                description={`${wallet.depositRemaining.amount} ${wallet.depositRemaining.asset} remaining`}
                action={
                    canPay
                        ? { label: 'Pay security deposit', href: '/security-deposit' }
                        : wallet.topupAvailable
                          ? { label: 'Top up wallet', href: '/wallet/top-up' }
                          : undefined
                }
            />
        );
    }
    return (
        <UserStatusBanner
            tone="success"
            title="You're ready"
            description="Your identity is verified and your wallet is active."
        />
    );
}

export default function Dashboard({ account, kycStatus, wallet }: Props) {
    const name = account?.displayName?.trim();
    return (
        <UserLayout>
            <Head title="Home" />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader
                    title={name ? `Hello, ${name}` : 'Hello'}
                    description="Here’s what matters right now."
                />
                {wallet ? (
                    <UserBalanceHero
                        amount={wallet.available.amount}
                        asset={wallet.available.asset}
                    />
                ) : null}
                <NextStep kycStatus={kycStatus} wallet={wallet} />
            </div>
        </UserLayout>
    );
}
