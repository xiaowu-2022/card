import { Link, usePage } from '@inertiajs/react';
import { CreditCard, Home, UserRound, WalletCards } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import type { SharedProps } from '@/types/global';

const nav = [
    { label: 'Home', href: '/demo', icon: Home },
    { label: 'Wallet', href: '/demo/wallet', icon: WalletCards },
    { label: 'Cards', href: '/demo/cards', icon: CreditCard },
    { label: 'Account', href: '#', icon: UserRound },
];

export function UserLayout({ children }: { children: ReactNode }) {
    const { tenant } = usePage<SharedProps>().props;
    const style = {
        '--tenant-primary': tenant?.branding.primaryColor ?? '#155EEF',
    } as CSSProperties;
    return (
        <div className="min-h-screen" style={style}>
            <header className="sticky top-0 z-30 border-b bg-surface/95">
                <div className="mx-auto flex h-16 max-w-7xl items-center justify-between px-4 sm:px-6">
                    <AppMark name={tenant?.branding.brandName ?? 'Aperture Cards'} />
                    <span className="rounded-full bg-muted px-3 py-1 text-xs font-semibold text-muted-foreground">
                        DEMO
                    </span>
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
