import { Link, usePage } from '@inertiajs/react';
import type { ReactNode } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import { UserBottomNavigation } from '@/components/user/UserBottomNavigation';
import { userNavigation } from '@/components/user/user-navigation';
import { cn } from '@/lib/utils';
import { userThemeStyle } from '@/lib/user-theme';
import type { SharedProps } from '@/types/global';

export function UserLayout({ children }: { children: ReactNode }) {
    const page = usePage<SharedProps>();
    const { tenant, auth, flash } = page.props;
    const brand = tenant?.branding.brandName ?? 'Aperture Cards';
    const style = userThemeStyle(tenant?.branding.primaryColor);
    return (
        <div className="user-theme min-h-screen bg-background" style={style}>
            <header className="sticky top-0 z-30 bg-background/95">
                <div className="mx-auto flex h-16 max-w-6xl items-center justify-between px-4 sm:h-18 sm:px-6">
                    {tenant?.branding.logoUrl ? (
                        <img
                            src={tenant.branding.logoUrl}
                            alt={brand}
                            className="max-h-9 max-w-[11rem] object-contain object-left"
                        />
                    ) : (
                        <div className="min-w-0 max-w-[13rem] overflow-hidden sm:max-w-xs">
                            <AppMark name={brand} />
                        </div>
                    )}
                    {!auth.user ? (
                        <Link
                            href="/login"
                            className="min-h-11 px-2 py-3 text-sm font-semibold text-primary"
                        >
                            Sign in
                        </Link>
                    ) : null}
                </div>
            </header>
            <div className="mx-auto flex max-w-6xl md:gap-8 md:px-6">
                <aside className="hidden w-48 shrink-0 py-8 md:block">
                    <nav className="space-y-1" aria-label="Primary navigation">
                        {userNavigation.map(({ label, href, icon: Icon }) => {
                            const active = href !== null && page.url.startsWith(href);
                            const className = cn(
                                'flex min-h-11 items-center gap-3 rounded-xl px-3 py-2.5 text-sm font-medium',
                                active
                                    ? 'bg-[var(--user-primary-soft)] text-primary'
                                    : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                href === null && 'cursor-not-allowed opacity-50',
                            );
                            return href ? (
                                <Link
                                    key={label}
                                    href={href}
                                    className={className}
                                    aria-current={active ? 'page' : undefined}
                                >
                                    <Icon className="size-5" />
                                    {label}
                                </Link>
                            ) : (
                                <span key={label} className={className} aria-disabled="true">
                                    <Icon className="size-5" />
                                    {label}
                                    <span className="sr-only"> coming soon</span>
                                </span>
                            );
                        })}
                    </nav>
                </aside>
                <main className="min-w-0 flex-1 px-4 pt-3 pb-[calc(5.5rem+env(safe-area-inset-bottom))] sm:px-6 md:px-0 md:pt-8 md:pb-12">
                    {flash.success && (
                        <div
                            className="mb-5 rounded-[var(--user-radius-sm)] border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                            role="status"
                        >
                            {flash.success}
                        </div>
                    )}
                    {children}
                </main>
            </div>
            <UserBottomNavigation currentUrl={page.url} />
        </div>
    );
}
