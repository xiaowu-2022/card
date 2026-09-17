import { t, useClientTranslation } from '@/i18n';
import { Head, Link } from '@inertiajs/react';
import { CheckCircle2, ChevronRight, ShieldCheck } from 'lucide-react';
import { MoneyDisplay } from '@/components/user/UserMoney';
import { UserActivityList } from '@/components/user/UserActivityList';
import { walletActivityItems } from '@/lib/wallet-activity';
import { UserBalanceHero } from '@/components/user/UserBalanceHero';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserSection } from '@/components/user/UserSection';
import { UserStatusBanner } from '@/components/user/UserStatusBanner';
import { UserWalletActions } from '@/components/user/UserWalletActions';
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
    activation: { agent: boolean };
    activationSatisfied: boolean;
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
    withdrawalAvailable?: boolean;
    transferAvailable?: boolean;
};

function InactiveWallet({ eligibility }: { eligibility: Eligibility }) {
    useClientTranslation();
    if (eligibility.kycStatus === 'APPROVED' && eligibility.canActivate) {
        return (
            <section className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7">
                <span className="grid size-11 place-items-center rounded-full bg-emerald-50 text-success">
                    <CheckCircle2 className="size-5" />
                </span>
                <h2 className="mt-5 text-xl font-semibold">{t('Your identity is verified')}</h2>
                <p className="mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                    {t('Set up your wallet and review the security deposit requirement.')}
                </p>
                <Button className="mt-5 w-full sm:w-auto" asChild>
                    <Link href="/security-deposit">{t('Activate wallet')}</Link>
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
            <h2 className="mt-5 text-xl font-semibold">{t('Wallet is not activated')}</h2>
            <p className="mt-2 max-w-lg text-sm leading-6 text-muted-foreground">
                {t(copy[eligibility.kycStatus] ?? 'Wallet activation is currently unavailable.')}
            </p>
            {canVisitKyc ? (
                <Button asChild variant="secondary" className="mt-5 w-full sm:w-auto">
                    <Link href="/kyc">{t('Identity verification')}</Link>
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
    withdrawalAvailable = false,
    transferAvailable = false,
}: Props) {
    useClientTranslation();
    const activated = eligibility.wallet !== null && eligibility.available !== null;
    const operational =
        eligibility.userStatus === 'ACTIVE' && eligibility.tenantStatus === 'ACTIVE';
    const depositAvailable =
        operational &&
        eligibility.kycStatus === 'APPROVED' &&
        eligibility.walletStatus === 'ACTIVE';
    const activityItems = walletActivityItems(activity);

    return (
        <UserLayout>
            <Head title={t('Asset activity')} />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title={t('Asset activity')} backHref="/dashboard" />
                {!activated ? (
                    <>
                        <InactiveWallet eligibility={eligibility} />
                    </>
                ) : (
                    <>
                        <div className="user-overview">
                            <UserBalanceHero
                                amount={eligibility.available!.amount}
                                asset={eligibility.available!.asset}
                            />
                            <UserWalletActions
                                unavailableHref={
                                    ['NOT_SUBMITTED', 'PENDING', 'RESUBMISSION_REQUIRED'].includes(
                                        eligibility.kycStatus,
                                    )
                                        ? '/kyc'
                                        : !eligibility.wallet &&
                                            eligibility.kycStatus === 'APPROVED'
                                          ? '/security-deposit'
                                          : '/support'
                                }
                                topupAvailable={topupAvailable}
                                withdrawalAvailable={withdrawalAvailable}
                                transferAvailable={transferAvailable}
                                depositAvailable={depositAvailable}
                            />
                        </div>
                        {!operational ? (
                            <UserStatusBanner
                                tone="warning"
                                title={t('Wallet access is restricted')}
                                description={t(
                                    'You can review your balance, but financial actions are unavailable.',
                                )}
                            />
                        ) : null}
                        <section
                            className="rounded-[var(--user-radius-lg)] bg-surface p-5 sm:p-7"
                            aria-label={t('Security deposit')}
                        >
                            <div className="flex items-center gap-3">
                                <span className="grid size-11 shrink-0 place-items-center rounded-full bg-[var(--user-primary-soft)] text-primary">
                                    <ShieldCheck className="size-5" aria-hidden="true" />
                                </span>
                                <div className="min-w-0 flex-1">
                                    <h2 className="font-semibold">{t('Security deposit')}</h2>
                                    <p className="mt-1 text-xs text-muted-foreground">
                                        {eligibility.activation.agent
                                            ? t('Active agents do not need a security deposit.')
                                            : eligibility.depositSatisfied
                                              ? t('Requirement met')
                                              : t('Complete your requirement to access cards')}
                                    </p>
                                </div>
                                {depositAvailable ? (
                                    <Link
                                        href="/security-deposit"
                                        aria-label={t('View security deposit')}
                                        className="grid size-11 shrink-0 place-items-center rounded-full hover:bg-muted"
                                    >
                                        <ChevronRight className="size-5" />
                                    </Link>
                                ) : null}
                            </div>
                            <div className="mt-5 grid grid-cols-2 gap-4 border-t pt-4 text-sm">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {t('Already deposited')}
                                    </p>
                                    <p className="mt-1 break-all font-semibold">
                                        <MoneyDisplay {...eligibility.depositCurrent} compact />
                                    </p>
                                </div>
                                <div>
                                    <p className="text-xs text-muted-foreground">{t('Required')}</p>
                                    <p className="mt-1 break-all font-semibold">
                                        <MoneyDisplay {...eligibility.depositRequired} compact />
                                    </p>
                                </div>
                            </div>
                            {!eligibility.activationSatisfied ? (
                                <p className="mt-3 text-xs text-muted-foreground">
                                    <span className="mr-2">{t('Remaining requirement')}</span>
                                    <MoneyDisplay {...eligibility.depositRemaining} compact />
                                </p>
                            ) : null}
                            {depositFundingAvailable ? (
                                <Button asChild className="mt-5 w-full sm:w-auto">
                                    <Link href="/security-deposit">
                                        {t('Pay security deposit')}
                                    </Link>
                                </Button>
                            ) : null}
                        </section>
                        <div className="rounded-[var(--user-radius-lg)] bg-surface px-5 pt-5 pb-2 sm:px-7">
                            <UserSection title={t('Recent activity')}>
                                <UserActivityList items={activityItems} />
                            </UserSection>
                        </div>
                    </>
                )}
            </div>
        </UserLayout>
    );
}
