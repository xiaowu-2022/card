import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Boxes,
    Building2,
    CreditCard,
    FileCheck2,
    Menu,
    ServerCog,
    ShieldCheck,
    Users,
    WalletCards,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { AppMark } from '@/components/shared/AppMark';
import { Button } from '@/components/ui/button';
import { Alert, AlertDescription } from '@/components/ui/alert';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import type { SharedProps } from '@/types/global';

const groups = [
    {
        label: 'Workspace',
        items: [
            { label: 'Dashboard', href: '/platform/demo', icon: Activity },
            { label: 'Tenants', href: '/platform/tenants', icon: Building2 },
        ],
    },
    {
        label: 'Operations',
        items: [
            { label: 'Users', href: '#', icon: Users },
            { label: 'KYC', href: '#', icon: FileCheck2 },
            { label: 'Wallet', href: '#', icon: WalletCards },
            { label: 'Cards', href: '/platform/cards', icon: CreditCard },
            { label: 'Products', href: '/platform/card-products', icon: Boxes },
            { label: 'Providers', href: '#', icon: ServerCog },
        ],
    },
    { label: 'Control', items: [{ label: 'Audit & system', href: '#', icon: ShieldCheck }] },
];
const PlatformNav = () => (
    <nav className="mt-7 space-y-6">
        {groups.map((group) => (
            <div key={group.label}>
                <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    {group.label}
                </p>
                {group.items.map((item) => (
                    <Link
                        key={item.label}
                        href={item.href}
                        className="flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                    >
                        <item.icon className="size-4" />
                        {item.label}
                    </Link>
                ))}
            </div>
        ))}
    </nav>
);

export function PlatformLayout({ children }: { children: ReactNode }) {
    const { auth, flash } = usePage<SharedProps>().props;
    const initials = auth.admin?.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    return (
        <div className="min-h-screen">
            <aside className="fixed inset-y-0 left-0 hidden w-64 border-r bg-surface p-5 lg:block">
                <AppMark name="Aperture Platform" />
                <PlatformNav />
            </aside>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b bg-surface px-4 sm:px-6 lg:ml-64">
                <div className="lg:hidden">
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label="Open platform navigation"
                            >
                                <Menu className="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent>
                            <SheetTitle>
                                <AppMark name="Aperture Platform" />
                            </SheetTitle>
                            <SheetDescription className="sr-only">
                                Platform administration navigation
                            </SheetDescription>
                            <PlatformNav />
                        </SheetContent>
                    </Sheet>
                </div>
                <p className="hidden text-sm font-medium sm:block">Platform control center</p>
                <div className="flex items-center gap-3">
                    <span className="rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-info">
                        Sandbox
                    </span>
                    <span className="grid size-8 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                        {initials ?? 'PA'}
                    </span>
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => router.post('/platform/logout')}
                    >
                        Sign out
                    </Button>
                </div>
            </header>
            <main className="min-w-0 p-4 sm:p-6 lg:ml-64 lg:p-8 xl:p-10">
                {flash.success && (
                    <Alert className="mb-6 border-emerald-200 bg-emerald-50">
                        <AlertDescription>{flash.success}</AlertDescription>
                    </Alert>
                )}
                {children}
            </main>
        </div>
    );
}
