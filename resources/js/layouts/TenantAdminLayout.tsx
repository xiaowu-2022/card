import { useAdminTranslation, t } from '@/i18n/admin';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { AdminLanguageSwitcher } from '@/components/admin/AdminLanguageSwitcher';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    CircleDollarSign,
    CreditCard,
    ListChecks,
    Menu,
    MessageSquare,
    ScanFace,
    ArrowUpRight,
    Settings,
    Users,
} from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
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

const items = [
    {
        label: 'Wealth settings',
        href: '/admin/wealth',
        icon: CircleDollarSign,
        permission: 'tenant_settings.manage',
    },
    {
        label: 'Support messages',
        href: '/admin/support',
        icon: MessageSquare,
        permission: 'support.manage',
    },
    { label: 'Overview', href: '/admin/demo', icon: BarChart3, permission: 'users.read' },
    { label: 'Users', href: '/admin/users', icon: Users, permission: 'users.read' },
    {
        label: 'Promotion management',
        href: '/admin/promotion',
        icon: Users,
        permission: 'tenant_settings.manage',
    },
    {
        label: 'Company fund book',
        href: '/admin/company-funds',
        icon: CircleDollarSign,
        permission: 'tenant_settings.manage',
    },
    { label: 'KYC review', href: '/admin/kyc', icon: ScanFace, permission: 'kyc.read' },
    {
        label: 'Top-ups',
        href: '/admin/topups',
        icon: CircleDollarSign,
        permission: 'wallet_topups.read',
    },
    {
        label: 'Withdrawals',
        href: '/admin/withdrawals',
        icon: ArrowUpRight,
        permission: 'withdrawals.read',
    },
    {
        label: 'Card operations',
        href: '/admin/cards',
        icon: CreditCard,
        permission: 'cards.read',
    },
    {
        label: 'Card products',
        href: '/admin/card-products',
        icon: CreditCard,
        permission: 'card_product.read',
    },
    {
        label: 'Setup',
        href: '/admin/onboarding',
        icon: ListChecks,
        permission: 'tenant_settings.manage',
    },
    { label: 'Team', href: '/admin/team', icon: Users, permission: 'admin_team.read' },
    {
        label: 'Settings',
        href: '/admin/settings/branding',
        icon: Settings,
        permission: 'tenant_settings.manage',
    },
];
const AdminNav = ({ permissions }: { permissions: string[] }) => (
    <nav className="mt-7 space-y-1">
        {items
            .filter((item) => permissions.includes(item.permission))
            .map((item) => (
                <Link
                    key={item.label}
                    href={item.href}
                    className="flex items-center gap-3 rounded-lg px-3 py-2.5 text-sm font-medium text-muted-foreground hover:bg-muted hover:text-foreground"
                >
                    <item.icon className="size-4" />
                    {t(item.label)}
                </Link>
            ))}
    </nav>
);

export function TenantAdminLayout({ children }: { children: ReactNode }) {
    useLocaleSync();
    useAdminTranslation();
    const { tenant, auth, flash } = usePage<SharedProps>().props;
    const style = {
        '--tenant-primary': tenant?.branding.primaryColor ?? '#155EEF',
    } as CSSProperties;
    return (
        <div className="min-h-screen" style={style}>
            <aside className="fixed inset-y-0 left-0 hidden w-64 border-r bg-surface p-5 lg:block">
                <AppMark name={tenant?.branding.brandName ?? t('Tenant Admin')} />
                <p className="mt-4 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                    {t('Tenant administration')}
                </p>
                <AdminNav permissions={auth.admin?.permissions ?? []} />
            </aside>
            <header className="sticky top-0 z-30 flex h-16 items-center justify-between border-b bg-surface px-4 sm:px-6 lg:ml-64">
                <div className="lg:hidden">
                    <Sheet>
                        <SheetTrigger asChild>
                            <Button
                                variant="ghost"
                                size="icon"
                                aria-label={t('Open admin navigation')}
                            >
                                <Menu className="size-5" />
                            </Button>
                        </SheetTrigger>
                        <SheetContent>
                            <SheetTitle>
                                <AppMark name={tenant?.branding.brandName ?? t('Tenant Admin')} />
                            </SheetTitle>
                            <SheetDescription className="sr-only">
                                {t('Tenant administration navigation')}
                            </SheetDescription>
                            <AdminNav permissions={auth.admin?.permissions ?? []} />
                        </SheetContent>
                    </Sheet>
                </div>
                <p className="hidden text-sm font-medium sm:block">{t('Tenant workspace')}</p>
                <div className="flex shrink-0 items-center gap-2 whitespace-nowrap sm:gap-3">
                    <AdminLanguageSwitcher />
                    <span className="grid size-8 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                        {auth.admin?.name.slice(0, 2).toUpperCase() ?? 'TA'}
                    </span>
                    <Button variant="ghost" size="sm" onClick={() => router.post('/admin/logout')}>
                        {t('Sign out')}
                    </Button>
                </div>
            </header>
            <main className="min-w-0 p-4 sm:p-6 lg:ml-64 lg:p-8 xl:p-10">
                {flash.success && (
                    <Alert className="mb-6 border-emerald-200 bg-emerald-50">
                        <AlertDescription>{t(flash.success)}</AlertDescription>
                    </Alert>
                )}
                {children}
            </main>
        </div>
    );
}
