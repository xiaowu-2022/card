import { Link, router, usePage } from '@inertiajs/react';
import { Home, IdCard, LockKeyhole, UserRound } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import { Button } from '@/components/ui/button';
import type { SharedProps } from '@/types/global';

const nav = [
    { label: 'Dashboard', href: '/dashboard', icon: Home },
    { label: 'Verify', href: '/kyc', icon: IdCard },
    { label: 'Account', href: '/account', icon: UserRound },
    { label: 'Security', href: '/account/security', icon: LockKeyhole },
];

export function UserLayout({ children }: { children: ReactNode }) {
    const { tenant, auth, flash } = usePage<SharedProps>().props;
    const style = {
        '--tenant-primary': tenant?.branding.primaryColor ?? '#155EEF',
    } as CSSProperties;
    return (
        <div className="min-h-screen" style={style}>
            <header className="sticky top-0 z-30 border-b bg-surface/95">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
                    <AppMark name={tenant?.branding.brandName ?? 'Aperture Cards'} />
                    {auth.user ? (
                        <Button variant="ghost" size="sm" onClick={() => router.post('/logout')}>
                            Sign out
                        </Button>
                    ) : (
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/login">Sign in</Link>
                        </Button>
                    )}
                </div>
            </header>
            <div className="mx-auto flex max-w-7xl">
                <aside className="hidden min-h-[calc(100vh-4rem)] w-60 border-r bg-surface p-4 md:block">
                    <nav className="space-y-1">
                        {nav.map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                            >
                                <item.icon className="size-4" />
                                {item.label}
                            </Link>
                        ))}
                    </nav>
                </aside>
                <main className="min-w-0 flex-1 px-4 py-6 pb-24 sm:px-6 md:py-8 md:pb-8 lg:px-10">
                    {flash.success && (
                        <div
                            className="mb-6 rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-900"
                            role="status"
                        >
                            {flash.success}
                        </div>
                    )}
                    {children}
                </main>
            </div>
            <nav className="fixed inset-x-0 bottom-0 z-40 grid grid-cols-4 border-t bg-surface px-1 pb-[max(.5rem,env(safe-area-inset-bottom))] pt-2 md:hidden">
                {nav.map((item) => (
                    <Link
                        key={item.label}
                        href={item.href}
                        className="flex min-h-14 flex-col items-center justify-center gap-1 text-xs font-medium text-muted-foreground"
                    >
                        <item.icon className="size-5" />
                        {item.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}
