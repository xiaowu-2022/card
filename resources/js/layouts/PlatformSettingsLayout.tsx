import { useEditor } from '@/components/admin/editor-context';
import type { ReactNode } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformSettingsNavigation } from '@/components/admin/PlatformSettingsNavigation';
import { PageHeader } from '@/components/shared/PageHeader';
import { t } from '@/i18n/admin';

export function PlatformSettingsLayout({ children }: { children: ReactNode }) {
    const editor = useEditor();
    if (editor) return <>{children}</>;
    return (
        <PlatformLayout>
            <div className="space-y-6">
                <PageHeader title={t('System settings')} />
                <PlatformSettingsNavigation />
                {children}
            </div>
        </PlatformLayout>
    );
}
