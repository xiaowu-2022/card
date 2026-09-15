import { t, useClientTranslation } from '@/i18n';
import { Head, Link, usePage } from '@inertiajs/react';
import { ChevronRight, Settings, ShieldCheck, Users, MessageSquare, UserRound } from 'lucide-react';
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

export default function Account({ kycStatus }: { kycStatus: string }) {
    useClientTranslation();
    const { auth } = usePage<SharedProps>().props;
    const name = auth.user?.displayName?.trim() || t('Account user');
    const items = [
        { title: t('Account and security'), icon: ShieldCheck, href: '/account/security' },
        { title: t('Promotion center'), icon: Users, href: '/promotion' },
        { title: t('Customer support'), icon: MessageSquare, href: '/support' },
    ];
    return (
        <UserLayout>
            <Head title={t('Me')} />
            <h1 className="sr-only">{t('Me')}</h1>
            <div className="user-account-page">
                <section className="user-profile">
                    <div className="user-account-identity">
                        <span className="user-account-avatar" aria-hidden="true">
                            <UserRound />
                        </span>
                        <div className="min-w-0 flex-1">
                            <h2 className="user-profile-name">{name}</h2>
                            <p className="user-profile-contact">
                                {maskedContact(auth.user?.email, auth.user?.phone)}
                            </p>
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
