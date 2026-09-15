import { Toaster as Sonner } from 'sonner';
import { t, useClientTranslation } from '@/i18n';

export function Toaster() {
    useClientTranslation();
    return <Sonner position="top-right" richColors containerAriaLabel={t('Notifications')} />;
}
