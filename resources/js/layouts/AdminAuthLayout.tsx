import type { ReactNode } from 'react';
import { usePage } from '@inertiajs/react';
import { AppMark } from '@/components/shared/AppMark';
import { AdminLanguageSwitcher } from '@/components/admin/AdminLanguageSwitcher';
import { useLocaleSync } from '@/i18n/useLocaleSync';
import { t, useAdminTranslation } from '@/i18n/admin';
import type { SharedProps } from '@/types/global';

export function AdminAuthLayout({ children }: { children: ReactNode }) {
    useLocaleSync();
    useAdminTranslation();
    const { tenant, i18n } = usePage<SharedProps>().props;
    return (
        <div className="min-h-screen bg-background">
            <header className="flex min-h-16 items-center justify-between gap-3 border-b bg-surface px-4 sm:px-6">
                <div className="min-w-0 max-w-[60%] overflow-hidden">
                    <AppMark
                        name={
                            i18n.surface === 'platform'
                                ? 'Aperture Platform'
                                : (tenant?.branding.brandName ?? t('Tenant Admin'))
                        }
                    />
                </div>
                <AdminLanguageSwitcher />
            </header>
            {children}
        </div>
    );
}
