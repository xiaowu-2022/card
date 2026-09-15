import { t, useClientTranslation } from '@/i18n';
import { Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Menu } from 'lucide-react';
import type { ReactNode } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { SharedProps } from '@/types/global';
import { userThemeStyle } from '@/lib/user-theme';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { LanguageSwitcher } from '@/components/user/LanguageSwitcher';
import { AuthBrand } from '@/components/user/AuthBrand';

export function PublicLayout({
    children,
    compact = false,
}: {
    children: ReactNode;
    compact?: boolean;
}) {
    useClientTranslation();
    useLocaleSync();
    const { tenant } = usePage<SharedProps>().props;
    const brand = tenant?.branding.brandName ?? 'Aperture Cards';
    const style = userThemeStyle(tenant?.branding.primaryColor);
    if (compact) {
        return (
            <div className="user-theme min-h-screen bg-background text-foreground" style={style}>
                <div className="user-auth-shell">
                    <header className="user-auth-banner">
                        <div className="flex w-full items-center justify-between">
                            <Link
                                href="/login"
                                aria-label={t('Back to sign in')}
                                className="grid size-11 place-items-center"
                            >
                                <ArrowLeft className="size-7" />
                            </Link>
                            <LanguageSwitcher />
                        </div>
                        <AuthBrand tenant={tenant} />
                    </header>
                    <main className="user-auth-content user-auth-flow">{children}</main>
                </div>
            </div>
        );
    }
    return (
        <div
            className={
                compact ? 'user-theme min-h-screen bg-background' : 'min-h-screen bg-surface'
            }
            style={style}
        >
            <header className={compact ? '' : 'border-b'}>
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <Link href="/" className="min-w-0 max-w-[13rem] overflow-hidden sm:max-w-xs">
                        {tenant?.branding.logoUrl ? (
                            <img
                                src={tenant.branding.logoUrl}
                                alt={brand}
                                className="max-h-9 max-w-[11rem] object-contain object-left"
                            />
                        ) : (
                            <AppMark name={brand} />
                        )}
                    </Link>
                    {!compact && (
                        <nav className="hidden items-center gap-2 sm:flex">
                            {tenant && <LanguageSwitcher />}
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/login">{t('Log in')}</Link>
                            </Button>
                            <Button asChild size="sm">
                                <Link href="/register">{t('Register')}</Link>
                            </Button>
                        </nav>
                    )}
                    {!compact && (
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button
                                    variant="ghost"
                                    size="icon"
                                    className="sm:hidden"
                                    aria-label={t('Open navigation')}
                                >
                                    <Menu className="size-5" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent closeLabel={t('Close menu')}>
                                <SheetTitle>
                                    <AppMark name={brand} />
                                </SheetTitle>
                                <SheetDescription className="sr-only">
                                    {t('Public navigation')}
                                </SheetDescription>
                                <nav className="mt-8 grid gap-2">
                                    {tenant && <LanguageSwitcher />}
                                    <Button asChild variant="ghost" className="justify-start">
                                        <Link href="/login">{t('Log in')}</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href="/register">{t('Register')}</Link>
                                    </Button>
                                </nav>
                            </SheetContent>
                        </Sheet>
                    )}
                </div>
            </header>
            <main>{children}</main>
            {!compact && (
                <footer className="border-t bg-background">
                    <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:justify-between sm:px-6 lg:px-8">
                        <p>© 2026 {brand}.</p>
                        <p>{t('Support · Privacy · Terms')}</p>
                    </div>
                </footer>
            )}
        </div>
    );
}
