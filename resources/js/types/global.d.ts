import type { PageProps as InertiaPageProps } from '@inertiajs/core';

export type MoneyAmount = string;

export interface TenantSharedProps {
    id: string;
    name: string;
    branding: { brandName: string; primaryColor: string; logoUrl: string | null };
    locales: string[];
}

export interface SharedProps extends InertiaPageProps {
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
    };
    flash: { success: string | null };
}
