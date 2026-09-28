import { Link, usePage } from '@inertiajs/react';
import { t } from '@/i18n/admin';
import { cn } from '@/lib/utils';
import type { SharedProps } from '@/types/global';

const platformSettingsTabs = [
    { label: 'Asset settings', href: '/platform/settings/assets', permission: 'tenant.manage' },
    {
        label: 'Domain configurations',
        href: '/platform/settings/domains',
        permission: 'tenant.manage',
    },
    { label: 'SMS configurations', href: '/platform/settings/sms', permission: 'tenant.manage' },
    {
        label: 'Email configurations',
        href: '/platform/settings/email',
        permission: 'tenant.manage',
    },
    { label: 'OSS storage', href: '/platform/settings/oss', permission: 'storage.manage' },
    {
        label: 'Identity verification settings',
        href: '/platform/settings/kyc',
        permission: 'tenant.manage',
    },
];

export function PlatformSettingsNavigation() {
    const { url, props } = usePage<SharedProps>();
    const permissions = props.auth.admin?.permissions ?? [];
    const path = url.split('?')[0];
    return (
        <nav aria-label={t('System settings')} className="flex gap-1 border-b">
            {platformSettingsTabs
                .filter((tab) => permissions.includes(tab.permission))
                .map((tab) => (
                    <Link
                        key={tab.href}
                        href={tab.href}
                        aria-current={path === tab.href ? 'page' : undefined}
                        className={cn(
                            'border-b-2 px-5 py-3 text-sm font-medium whitespace-nowrap transition-colors',
                            path === tab.href
                                ? 'border-primary text-primary'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        )}
                    >
                        {t(tab.label)}
                    </Link>
                ))}
        </nav>
    );
}
