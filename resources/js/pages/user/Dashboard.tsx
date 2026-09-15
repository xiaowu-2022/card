import { systemMoney } from '@/lib/system-money';
import { t, useClientTranslation } from '@/i18n';
import { Head, Link } from '@inertiajs/react';
import { ArrowUpRight, ChevronRight, CreditCard } from 'lucide-react';
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
    depositRemaining: { amount: MoneyAmount; asset: string };
    depositHasEnoughAvailable: boolean;
    topupAvailable: boolean;
    withdrawalAvailable: boolean;
    transferAvailable: boolean;
};
type Props = {
    cardOverview: {
        count: number;
        pending: number;
        items: { id: string; last4: string; balance: string | null; state: string }[];
    };
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
    if (!wallet.depositSatisfied) {
        return (
            <UserStatusBanner
                tone="warning"
                title={t('Security deposit required')}
                description={t('Remaining: {{amount}}', {
                    amount: systemMoney(wallet.depositRemaining.amount),
                })}
                action={{ label: t('Pay security deposit'), href: '/security-deposit' }}
            />
        );
    }
    return null;
}

export default function Dashboard({ kycStatus, wallet, activity = [], cardOverview }: Props) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Home')} />
            <div className="space-y-6 sm:space-y-8">
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
                        depositAvailable={wallet?.status === 'ACTIVE' && kycStatus === 'APPROVED'}
                    />
                </div>
                <NextStep kycStatus={kycStatus} wallet={wallet} />
                {cardOverview.count > 0 ? (
                    <section className="rounded-2xl bg-surface p-5">
                        <div className="flex items-center justify-between">
                            <h2 className="font-semibold">{t('My cards')}</h2>
                            <Link href="/cards" className="py-2 text-sm underline">
                                {t('View cards')}
                            </Link>
                        </div>
                        {cardOverview.pending > 0 && (
                            <Link href="/cards" className="block rounded-lg bg-muted p-3 text-sm">
                                {t('Pending card operations: {{count}}', {
                                    count: cardOverview.pending,
                                })}
                            </Link>
                        )}
                        {cardOverview.items.map((card) => (
                            <Link
                                href="/cards"
                                key={card.id}
                                className="flex items-center justify-between gap-3 border-t py-3"
                            >
                                <div>
                                    <p>{t('Card ending in {{last4}}', { last4: card.last4 })}</p>
                                    <p className="text-xs text-muted-foreground">{t(card.state)}</p>
                                </div>
                                <span className="font-semibold">
                                    {card.balance === null
                                        ? t('Pending sync')
                                        : systemMoney(card.balance)}
                                </span>
                            </Link>
                        ))}
                    </section>
                ) : (
                    <Link
                        href="/cards"
                        className="user-feature-panel group flex items-center gap-4 bg-surface"
                    >
                        <div className="min-w-0 flex-1">
                            <p className="text-xs font-semibold uppercase tracking-[.14em] text-muted-foreground">
                                {t('Your everyday card')}
                            </p>
                            <h2 className="mt-2 text-xl font-semibold tracking-tight sm:text-2xl">
                                {t('Explore your next card')}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-muted-foreground">
                                {t('Manage your cards and explore available card products.')}
                            </p>
                            <span className="mt-4 inline-flex items-center gap-2 text-sm font-semibold">
                                {t('View cards')}
                                <ArrowUpRight className="size-4" aria-hidden="true" />
                            </span>
                        </div>
                        <span className="user-feature-card" aria-hidden="true">
                            <CreditCard
                                className="size-10 sm:size-20"
                                strokeWidth={1.4}
                                aria-hidden="true"
                            />
                        </span>
                    </Link>
                )}

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
                        <UserActivityList items={walletActivityItems(activity)} />
                    </UserSection>
                </div>
            </div>
        </UserLayout>
    );
}
