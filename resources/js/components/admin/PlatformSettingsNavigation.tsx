import { router, usePage } from '@inertiajs/react';
import { t } from '@/i18n/admin';
import { SettingsTabs } from './SettingsTabs';
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
        <SettingsTabs
            label={t('System settings')}
            value={path ?? ''}
            items={platformSettingsTabs
                .filter((tab) => permissions.includes(tab.permission))
                .map((tab) => ({ value: tab.href, label: tab.label }))}
            onChange={(href) => router.get(href)}
        />
    );
}
