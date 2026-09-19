import { Link } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, Settings2 } from 'lucide-react';
import { t } from '@/i18n/admin';
import { cn } from '@/lib/utils';

export function AssetNavigation({ active }: { active: 'deposit' | 'withdrawal' | 'settings' }) {
    const items = [
        {
            id: 'deposit',
            label: 'Multi-currency deposits',
            href: '/platform/asset-deposits',
            icon: ArrowDownLeft,
        },
        {
            id: 'withdrawal',
            label: 'Multi-currency withdrawals',
            href: '/platform/asset-withdrawals',
            icon: ArrowUpRight,
        },
        {
            id: 'settings',
            label: 'Multi-currency settings',
            href: '/platform/settings/assets',
            icon: Settings2,
        },
    ];
    return (
        <nav
            aria-label={t('Multi-currency settings')}
            className="grid grid-cols-3 gap-1 rounded-xl border bg-surface p-1.5 sm:flex sm:w-fit"
        >
            {items.map(({ id, label, href, icon: Icon }) => (
                <Link
                    key={id}
                    href={href}
                    aria-current={active === id ? 'page' : undefined}
                    className={cn(
                        'flex min-h-11 min-w-0 items-center justify-center gap-2 rounded-lg px-3 py-2 text-center text-sm font-medium transition-colors focus-visible:outline focus-visible:outline-2 focus-visible:outline-primary sm:px-5',
                        active === id
                            ? 'bg-primary text-primary-foreground shadow-sm'
                            : 'text-muted-foreground hover:bg-muted hover:text-foreground',
                    )}
                >
                    <Icon className="hidden size-4 shrink-0 sm:block" />
                    <span>{t(label)}</span>
                </Link>
            ))}
        </nav>
    );
}
