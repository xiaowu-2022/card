import { useEditor } from '@/components/admin/editor-context';
import type { ReactNode } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformSettingsNavigation } from '@/components/admin/PlatformSettingsNavigation';
import { t } from '@/i18n/admin';

export function PlatformSettingsLayout({ children }: { children: ReactNode }) {
    const editor = useEditor();
    if (editor) return <>{children}</>;
    return (
        <PlatformLayout title={t('System settings')}>
            <div className="min-w-0 space-y-4">
                <PlatformSettingsNavigation />
                {children}
            </div>
        </PlatformLayout>
    );
}
