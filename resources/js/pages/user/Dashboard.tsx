import { AssetCenter, type AssetOverview } from '@/components/user/AssetCenter';
import { systemMoney } from '@/lib/system-money';
import { t, useClientTranslation } from '@/i18n';
import { Head, Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { UserBalanceHero } from '@/components/user/UserBalanceHero';
import { UserActivityList } from '@/components/user/UserActivityList';
import { walletActivityItems, type WalletActivity } from '@/lib/wallet-activity';
import { UserWalletActions } from '@/components/user/UserWalletActions';
import { UserSection } from '@/components/user/UserSection';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserLayout } from '@/layouts/UserLayout';
import type { MoneyAmount } from '@/types/global';

type KycStatus = 'NOT_SUBMITTED' | 'PENDING' | 'APPROVED' | 'REJECTED' | 'RESUBMISSION_REQUIRED';
type WalletState = {
    status: string;
    available: { amount: MoneyAmount; asset: string };
    depositSatisfied: boolean;
    activationSatisfied: boolean;
    depositRemaining: { amount: MoneyAmount; asset: string };
    depositHasEnoughAvailable: boolean;
    topupAvailable: boolean;
    withdrawalAvailable: boolean;
    transferAvailable: boolean;
};
type Props = {
    assetOverview?: AssetOverview | null;
    account: { displayName: string | null; status: string; verifiedChannel: string } | null;
    kycStatus: KycStatus;
    wallet: WalletState | null;
    activity?: WalletActivity[];
};

function NextStep({ kycStatus, wallet }: { kycStatus: KycStatus; wallet: WalletState | null }) {
    useClientTranslation();
    if (kycStatus === 'NOT_SUBMITTED') {
        return (
            <UserStatusBanner
                title={t('Complete identity verification')}
                description={t('Verify your identity before using financial services.')}
                action={{ label: t('Verify now'), href: '/kyc' }}
            />
        );
    }
    if (kycStatus === 'PENDING') {
        return (
            <UserStatusBanner
                tone="pending"
                title={t('Verification under review')}
                description={t(
                    'Your information has been submitted. We will let you know when review is complete.',
                )}
            />
        );
    }
    if (kycStatus === 'RESUBMISSION_REQUIRED') {
        return (
            <UserStatusBanner
                tone="warning"
                title={t('Action required')}
                description={t('We need updated identity documents before you can continue.')}
                action={{ label: t('Review request'), href: '/kyc' }}
            />
        );
    }
    if (kycStatus === 'REJECTED') {
        return (
            <UserStatusBanner
                tone="warning"
                title={t('Verification unavailable')}
                description={t(
                    'We could not verify your identity. Contact support for assistance.',
                )}
                action={{ label: t('Customer support'), href: '/support' }}
            />
        );
    }
    if (!wallet) {
        return (
            <UserStatusBanner
                tone="success"
                title={t('Set up your wallet')}
                description={t('Set up your wallet and review the security deposit requirement.')}
                action={{ label: t('Activate wallet'), href: '/security-deposit' }}
            />
        );
    }
    if (!wallet.activationSatisfied) {
        return (
            <UserStatusBanner
                tone="warning"
                title={t('Account pending activation')}
                description={t('Remaining: {{amount}}', {
                    amount: systemMoney(wallet.depositRemaining.amount),
                })}
                action={{ label: t('Activate now'), href: '/promotion/membership' }}
            />
        );
    }
    return null;
}

export default function Dashboard({ kycStatus, wallet, activity = [], assetOverview }: Props) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Home')} />
            <div className="space-y-6 sm:space-y-8">
                {assetOverview ? (
                    <AssetCenter
                        overview={assetOverview}
                        prerequisiteHref={
                            kycStatus !== 'APPROVED'
                                ? '/kyc'
                                : !wallet
                                  ? '/promotion/membership'
                                  : wallet.status !== 'ACTIVE'
                                    ? '/wallet'
                                    : undefined
                        }
                    />
                ) : (
                    <div className="user-overview">
                        <h1 className="sr-only">{t('Home')}</h1>
                        {wallet ? (
                            <UserBalanceHero
                                amount={wallet.available.amount}
                                asset={wallet.available.asset}
                                assetLabel="$"
                            />
                        ) : (
                            <div className="py-7 text-center">
                                <p className="text-3xl font-semibold tracking-tight">
                                    {t('Your everyday wallet')}
                                </p>
                                <p className="mt-3 text-sm text-muted-foreground">
                                    {t('A single balance. Everything in one place.')}
                                </p>
                            </div>
                        )}
                        <UserWalletActions
                            unavailableHref={
                                ['NOT_SUBMITTED', 'PENDING', 'RESUBMISSION_REQUIRED'].includes(
                                    kycStatus,
                                )
                                    ? '/kyc'
                                    : kycStatus === 'REJECTED'
                                      ? '/support'
                                      : !wallet
                                        ? '/security-deposit'
                                        : '/support'
                            }
                            topupAvailable={wallet?.topupAvailable ?? false}
                            withdrawalAvailable={wallet?.withdrawalAvailable ?? false}
                            transferAvailable={wallet?.transferAvailable ?? false}
                            depositAvailable={
                                wallet?.status === 'ACTIVE' && kycStatus === 'APPROVED'
                            }
                        />
                    </div>
                )}
                {(!assetOverview || kycStatus !== 'APPROVED') && (
                    <NextStep kycStatus={kycStatus} wallet={wallet} />
                )}
                {!assetOverview && (
                    <div className="rounded-[var(--user-radius-lg)] bg-surface px-5 pt-5 pb-2 sm:px-7">
                        <UserSection
                            title={t('Latest activity')}
                            action={
                                <Link
                                    href="/wallet"
                                    className="inline-flex min-h-11 items-center gap-1 text-xs font-medium text-muted-foreground"
                                >
                                    {t('More')}
                                    <ChevronRight className="size-4" aria-hidden="true" />
                                </Link>
                            }
                        >
                            <UserActivityList
                                items={walletActivityItems(assetOverview ? [] : activity)}
                            />
                        </UserSection>
                    </div>
                )}
            </div>
        </UserLayout>
    );
}
