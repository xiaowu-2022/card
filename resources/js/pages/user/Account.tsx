import { t, useClientTranslation } from '@/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import {
    ChevronRight,
    Settings,
    ShieldCheck,
    Users,
    MessageSquare,
    ArrowUpRight,
    Coins,
    Landmark,
    Info,
    ListOrdered,
} from 'lucide-react';
import { promotionLevel } from '@/lib/paid-promotion';
import { UserLayout } from '@/layouts/UserLayout';
import type { SharedProps } from '@/types/global';
import { AccountIdCopy } from '@/components/user/AccountIdCopy';
import { AccountVerificationLink } from '@/components/user/AccountVerificationLink';

function maskedContact(email?: string | null, phone?: string | null) {
    if (email) {
        const [local = '', domain = ''] = email.split('@');
        return `${local.slice(0, 1)}***@${domain}`;
    }
    return phone ? `${phone.slice(0, 3)}••••${phone.slice(-3)}` : '';
}

export default function Account({
    kycStatus,
    promotionRank,
    accountQualified,
}: {
    kycStatus: string;
    promotionRank: number;
    accountQualified: boolean;
}) {
    useClientTranslation();
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.displayName?.trim() || t('Account user');
    const items = [
        { title: t('Account and security'), icon: ShieldCheck, href: '/account/security' },
        { title: t('Promotion center'), icon: Users, href: '/promotion' },
        { title: t('Funds activity'), icon: ListOrdered, href: '/funds' },
        { title: t('Commission'), icon: Coins, href: '/promotion/commissions' },
        { title: t('Wealth management'), icon: Landmark, href: '/wealth' },
        { title: t('Customer support'), icon: MessageSquare, href: '/support' },
        { title: t('About us'), icon: Info, href: '/about' },
    ];
    return (
        <UserLayout>
            <Head title={t('Me')} />
            <h1 className="sr-only">{t('Me')}</h1>
            <div className="user-account-page">
                <section className="user-profile">
                    <div className="user-account-identity">
                        <div className="min-w-0 flex-1">
                            <h2 className="user-profile-name">{name}</h2>
                            <p className="user-profile-contact">
                                {maskedContact(auth.user?.email, auth.user?.phone)}
                            </p>
                        </div>
                        <div className="flex max-w-[55%] shrink-0 items-center gap-1 sm:gap-3">
                            <span
                                className="min-w-0 text-sm font-medium text-[var(--user-primary)]"
                                aria-label={t('My promotion level')}
                            >
                                {accountQualified
                                    ? promotionLevel(promotionRank)
                                    : t('Account inactive')}
                            </span>
                            <Link
                                href="/promotion/membership"
                                className="inline-flex min-h-11 shrink-0 items-center gap-1 whitespace-nowrap rounded-full px-2 text-sm font-medium text-[var(--user-primary)] hover:bg-[var(--user-primary-soft)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-[var(--user-primary)]"
                            >
                                <ArrowUpRight className="size-4" aria-hidden="true" />
                                {t('Upgrade')}
                            </Link>
                        </div>
                    </div>
                    {auth.user?.accountId && (
                        <AccountIdCopy key={auth.user.id} accountId={auth.user.accountId} />
                    )}
                    <AccountVerificationLink status={kycStatus} />
                </section>
                <section
                    className="user-menu-group user-menu-groups"
                    aria-label={t('Common features')}
                >
                    <h2 className="sr-only">{t('Common features')}</h2>
                    <div className="user-menu-grid">
                        {items.map(({ title, icon: Icon, href }) => (
                            <Link key={href} href={href} className="user-menu-item">
                                <span className="user-menu-icon">
                                    <Icon aria-hidden="true" />
                                </span>
                                <span className="user-menu-label">{title}</span>
                            </Link>
                        ))}
                    </div>
                </section>
                <Link href="/account/settings" className="user-settings-row">
                    <Settings
                        className="size-5 shrink-0 text-[var(--user-primary)]"
                        aria-hidden="true"
                    />
                    <span className="min-w-0 flex-1">{t('Settings')}</span>
                    <ChevronRight
                        className="size-4 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                </Link>
            </div>
        </UserLayout>
    );
}
