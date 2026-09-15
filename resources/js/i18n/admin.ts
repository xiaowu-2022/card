import { useTranslation } from 'react-i18next';
import { clientI18n, errorMessage as commonErrorMessage } from './index';

export { dateTime } from './index';

export function t(key: string, values?: Record<string, string | number>): string {
    return clientI18n.t(key, { ...values, ns: 'admin' });
}

export function useAdminTranslation() {
    return useTranslation('admin', { i18n: clientI18n });
}

export function countryName(code: string): string {
    if (!/^[A-Z]{2}$/.test(code)) return code;
    return new Intl.DisplayNames([clientI18n.language], { type: 'region' }).of(code) ?? code;
}

export function errorMessage(message?: string): string | undefined {
    if (!message) return undefined;
    if (clientI18n.exists(message, { ns: 'admin' })) return t(message);
    return commonErrorMessage(message);
}
