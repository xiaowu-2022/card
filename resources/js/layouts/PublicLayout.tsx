import { Link, usePage } from '@inertiajs/react';
import { Globe, Menu } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
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

export function PublicLayout({ children }: { children: ReactNode }) {
    const { tenant } = usePage<SharedProps>().props;
    const brand = tenant?.branding.brandName ?? 'Aperture Cards';
    const style = {
        '--tenant-primary': tenant?.branding.primaryColor ?? '#155EEF',
    } as CSSProperties;
    return (
        <div className="min-h-screen bg-surface" style={style}>
            <header className="border-b">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6 lg:px-8">
                    <Link href="/">
                        <AppMark name={brand} />
                    </Link>
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
                </div>
            </header>
            <main>{children}</main>
            <footer className="border-t bg-background">
                <div className="mx-auto flex max-w-7xl flex-col gap-2 px-4 py-8 text-sm text-muted-foreground sm:flex-row sm:justify-between sm:px-6 lg:px-8">
                    <p>© 2026 {brand}.</p>
                    <p>Support · Privacy · Terms</p>
                </div>
            </footer>
        </div>
    );
}
