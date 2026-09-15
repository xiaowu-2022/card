import { t } from '@/i18n';

export function cardDisplayName(name: string): string {
    // This default product has an explicitly localized consumer label. Custom names stay intact.
    return name === 'Mille Card' || name === 'U Card' ? t('U Card') : name;
}
