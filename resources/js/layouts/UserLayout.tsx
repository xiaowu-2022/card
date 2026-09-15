import { t, useClientTranslation } from '@/i18n';
import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { Aperture, MessageSquare } from 'lucide-react';
import { UserBottomNavigation } from '@/components/user/UserBottomNavigation';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { userThemeStyle } from '@/lib/user-theme';
import type { SharedProps } from '@/types/global';

export function UserLayout({ children }: { children: ReactNode }) {
    useLocaleSync();
    useClientTranslation();
    const page = usePage<SharedProps>();
    const { tenant, auth, flash } = page.props;
    const companyName = tenant?.branding.brandName ?? 'Aperture Cards';
    const style = userThemeStyle(tenant?.branding.primaryColor);
    const promotionPath = page.url.split(/[?#]/)[0] ?? '';
    const promotionPage = promotionPath === '/promotion' || promotionPath.startsWith('/promotion/');
    const accountHome = page.url.split('?')[0] === '/account';
    const hideHeaderActions = ['/account', '/account/settings'].includes(
        page.url.split('?')[0] ?? '',
    );
    const support = page.url.split('?')[0] === '/support';
    const overview = ['/dashboard', '/wallet', '/demo'].includes(page.url.split('?')[0] ?? '');
    return (
        <div
            className="user-theme min-h-screen bg-[var(--user-canvas)] text-foreground"
            style={style}
        >
            <div
                className={`user-shell mx-auto min-h-screen ${overview ? 'user-shell-overview' : ''} ${support ? 'user-shell-support' : ''} ${accountHome ? 'user-shell-account' : ''} ${promotionPage ? 'user-shell-promotion' : ''}`}
            >
                <header className="user-header">
                    <div className="user-header-inner">
                        <Link
                            href={auth.user ? '/dashboard' : '/'}
                            aria-label={t('{{value1}} home', { value1: companyName })}
                            className="user-brand"
                        >
                            {tenant?.branding.logoUrl ? (
                                <img
                                    src={tenant.branding.logoUrl}
                                    alt={companyName}
                                    className="user-brand-logo"
                                />
                            ) : (
                                <Aperture
                                    className="user-brand-symbol"
                                    strokeWidth={2.5}
                                    aria-hidden="true"
                                />
                            )}
                            {!tenant?.branding.logoUrl && (
                                <span className="truncate">{companyName}</span>
                            )}
                        </Link>
                        {!hideHeaderActions && (
                            <div className="flex shrink-0 items-center">
                                <LanguageSwitcher variant="icon" />
                                {auth.user ? (
                                    <Link
                                        href={
                                            auth.user.status === 'ACTIVE'
                                                ? '/support'
                                                : '/account/security'
                                        }
                                        aria-label={t('Help and support')}
                                        className="user-header-action"
                                    >
                                        <MessageSquare strokeWidth={2.2} aria-hidden="true" />
                                    </Link>
                                ) : (
                                    <Link
                                        href="/login"
                                        className="min-h-11 px-2 py-3 text-sm font-semibold text-primary"
                                    >
                                        {t('Sign in')}
                                    </Link>
                                )}
                            </div>
                        )}
                    </div>
                </header>
                <main className={`user-main min-w-0 ${overview ? 'user-main-overview' : ''}`}>
                    {flash.success && (
                        <div
                            className="mb-5 rounded-[var(--user-radius-sm)] border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                            role="status"
                        >
                            {t(flash.success)}
                        </div>
                    )}
                    {children}
                </main>
                <UserBottomNavigation currentUrl={page.url} />
            </div>
        </div>
    );
}
