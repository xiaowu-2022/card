import { AdminEditorHost } from '@/components/admin/AdminEditor';
import { PlatformUiContext } from '@/components/admin/platform-ui-context';
import { useEditor } from '@/components/admin/editor-context';
import { useAdminTranslation, t } from '@/i18n/admin';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { AdminLanguageSwitcher } from '@/components/admin/AdminLanguageSwitcher';
import { Link, router, usePage } from '@inertiajs/react';
import {
    Activity,
    Boxes,
    Building2,
    CreditCard,
    Menu,
    MessageSquare,
    ReceiptText,
    ServerCog,
    ShieldCheck,
    Users,
} from 'lucide-react';
import type { ReactNode } from 'react';
import type { LucideIcon } from 'lucide-react';
import { AppMark } from '@/components/shared/AppMark';
import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
    SheetTrigger,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/global';

type PlatformNavItem = {
    label: string;
    href: string;
    icon: LucideIcon;
    permission?: string;
    anyPermissions?: string[];
};
const groups: { label: string; items: PlatformNavItem[] }[] = [
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
            {
                label: 'Partners',
                href: '/platform/partners',
                icon: Users,
                permission: 'partners.manage',
            },
            {
                label: 'Notifications',
                href: '/platform/notifications',
                icon: ReceiptText,
                permission: 'notifications.read',
            },
            {
                label: 'Customer support',
                href: '/platform/support',
                icon: MessageSquare,
                permission: 'support.read',
            },
            { label: 'Users', href: '/platform/users', icon: Users, permission: 'users.read' },
            { label: 'KYC', href: '/platform/kyc', icon: ShieldCheck, permission: 'kyc.read' },
            { label: 'Cards', href: '/platform/cards', icon: CreditCard },
            {
                label: 'Deposit orders',
                href: '/platform/topups',
                icon: ReceiptText,
                permission: 'wallet_topups.read',
            },
            {
                label: 'Withdrawal orders',
                href: '/platform/asset-withdrawals',
                icon: ReceiptText,
                permission: 'withdrawals.read',
            },
            { label: 'Products', href: '/platform/card-products', icon: Boxes },
            {
                label: 'Card providers',
                href: '/platform/card-providers',
                icon: ServerCog,
                permission: 'provider_operation.read',
            },
        ],
    },
    {
        label: 'Control',
        items: [
            {
                label: 'System settings',
                href: '/platform/settings',
                icon: ServerCog,
                anyPermissions: ['tenant.manage', 'storage.manage'],
            },
            {
                label: 'SaaS administrators',
                href: '/platform/administrators',
                icon: Users,
                permission: 'admin_team.read',
            },
            {
                label: 'Financial operation records',
                href: '/platform/financial-operations',
                icon: ShieldCheck,
                permission: 'audit.read',
            },
        ],
    },
];
const PlatformNav = () => {
    const { url, props } = usePage<SharedProps>();
    const permissions = props.auth.admin?.permissions ?? [];
    const path = url.split('?')[0] ?? '';
    const navPath = /^\/platform\/tenants\/[^/]+\/support\//.test(path)
        ? '/platform/support'
        : /^\/platform\/tenants\/[^/]+\/topups$/.test(path) || path === '/platform/asset-deposits'
          ? '/platform/topups'
          : path === '/platform/asset-tron-withdrawals'
            ? '/platform/asset-withdrawals'
            : path;
    const active = (href: string) => navPath === href || navPath.startsWith(href + '/');
    return (
        <nav className="mt-7 space-y-6">
            {groups.map((group) => (
                <div key={group.label}>
                    <p className="mb-2 px-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground">
                        {t(group.label)}
                    </p>
                    {group.items
                        .filter((item) => {
                            if (item.anyPermissions)
                                return item.anyPermissions.some((permission) =>
                                    permissions.includes(permission),
                                );
                            return (
                                !('permission' in item) ||
                                !item.permission ||
                                permissions.includes(item.permission)
                            );
                        })
                        .map((item) => (
                            <Link
                                key={item.label}
                                href={item.href}
                                aria-current={active(item.href) ? 'page' : undefined}
                                className={cn(
                                    'flex items-center gap-3 rounded-lg px-3 py-2 text-sm font-medium',
                                    active(item.href)
                                        ? 'bg-muted text-foreground'
                                        : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                                )}
                            >
                                <item.icon className="size-4" />
                                {t(item.label)}
                            </Link>
                        ))}
                </div>
            ))}
        </nav>
    );
};

export type PlatformLayoutProps = {
    children: ReactNode;
    title: string;
    description?: string;
    actions?: ReactNode;
};
export function PlatformLayout({ children, title, description, actions }: PlatformLayoutProps) {
    useLocaleSync();
    useAdminTranslation();
    const { auth } = usePage<SharedProps>().props;
    const editor = useEditor();
    if (editor)
        return (
            <>
                {description && <p className="mb-4 text-sm text-muted-foreground">{description}</p>}
                {actions && <div className="mb-4 flex justify-end gap-2">{actions}</div>}
                {children}
            </>
        );
    const initials = auth.admin?.name
        .split(' ')
        .map((part) => part[0])
        .join('')
        .slice(0, 2)
        .toUpperCase();
    return (
        <PlatformUiContext.Provider value={true}>
            <AdminEditorHost>
                <div data-platform-ui className="min-h-screen">
                    <aside className="fixed inset-y-0 left-0 hidden w-64 overflow-y-auto overscroll-y-contain border-r bg-surface p-5 lg:block">
                        <AppMark name="Aperture Platform" />
                        <PlatformNav />
                    </aside>
                    <header
                        data-platform-header
                        className="sticky top-0 z-30 flex h-14 min-w-0 items-center gap-4 border-b bg-surface px-5 lg:ml-64"
                    >
                        <div className="lg:hidden">
                            <Sheet>
                                <SheetTrigger asChild>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        aria-label={t('Open platform navigation')}
                                    >
                                        <Menu className="size-5" />
                                    </Button>
                                </SheetTrigger>
                                <SheetContent>
                                    <SheetTitle>
                                        <AppMark name="Aperture Platform" />
                                    </SheetTitle>
                                    <SheetDescription className="sr-only">
                                        {t('Platform administration navigation')}
                                    </SheetDescription>
                                    <PlatformNav />
                                </SheetContent>
                            </Sheet>
                        </div>
                        <h1 className="min-w-0 flex-1 truncate text-lg font-semibold" title={title}>
                            {title}
                        </h1>
                        {actions && (
                            <div className="flex shrink-0 items-center gap-2">{actions}</div>
                        )}
                        <div className="flex shrink-0 items-center gap-2 whitespace-nowrap sm:gap-3">
                            <AdminLanguageSwitcher />
                            <span className="grid size-8 place-items-center rounded-full bg-slate-900 text-xs font-semibold text-white">
                                {initials ?? 'PA'}
                            </span>
                            <Button
                                variant="ghost"
                                size="sm"
                                onClick={() => router.post('/platform/logout')}
                            >
                                {t('Sign out')}
                            </Button>
                        </div>
                    </header>
                    <main data-platform-content className="min-w-0 space-y-4 p-5 lg:ml-64">
                        {description && (
                            <p className="text-sm text-muted-foreground">{description}</p>
                        )}
                        {children}
                    </main>
                </div>
            </AdminEditorHost>
        </PlatformUiContext.Provider>
    );
}
