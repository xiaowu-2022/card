import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type MoneyAmount = string;

export interface TenantSharedProps {
    id: string;
    name: string;
    branding: { brandName: string; primaryColor: string; logoUrl: string | null };
    locales: string[];
}

export interface SharedProps extends InertiaPageProps {
    i18n: {
        locale: string;
        enabledLocales: string[];
        timezone: string;
        surface?: 'user' | 'tenant-admin' | 'platform';
    };
    unreadMessages?: number;
    unreadSupport?: number;
    requestId: string;
    tenant: TenantSharedProps | null;
    auth: {
        admin: null | {
            id: string;
            name: string;
            email: string;
            scope: 'PLATFORM' | 'TENANT';
            permissions: string[];
        };
        user: null | {
            id: string;
            accountId: string;
            displayName: string | null;
            email: string | null;
            phone: string | null;
            status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED';
        };
    };
    flash: { success: string | null };
}
