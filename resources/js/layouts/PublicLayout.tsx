import { Link, usePage } from '@inertiajs/react';
import { Globe, Menu } from 'lucide-react';
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

export function PublicLayout({
    children,
    compact = false,
}: {
    children: ReactNode;
    compact?: boolean;
}) {
    const { tenant } = usePage<SharedProps>().props;
    const brand = tenant?.branding.brandName ?? 'Aperture Cards';
    const style = userThemeStyle(tenant?.branding.primaryColor);
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
                            <Button variant="ghost" size="sm">
                                <Globe className="size-4" />
                                EN
                            </Button>
                            <Button asChild variant="ghost" size="sm">
                                <Link href="/login">Log in</Link>
                            </Button>
                            <Button asChild size="sm">
                                <Link href="/register">Register</Link>
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
                                    aria-label="Open navigation"
                                >
                                    <Menu className="size-5" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent>
                                <SheetTitle>
                                    <AppMark name={brand} />
                                </SheetTitle>
                                <SheetDescription className="sr-only">
                                    Public navigation
                                </SheetDescription>
                                <nav className="mt-8 grid gap-2">
                                    <Button asChild variant="ghost" className="justify-start">
                                        <Link href="/login">Log in</Link>
                                    </Button>
                                    <Button asChild>
                                        <Link href="/register">Register</Link>
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
                        <p>Support · Privacy · Terms</p>
                    </div>
                </footer>
            )}
        </div>
    );
}
