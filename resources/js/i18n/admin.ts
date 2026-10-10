import { adminAssetLabel } from '@/lib/admin-asset-label';
import { useTranslation } from 'react-i18next';
import { clientI18n, errorMessage as commonErrorMessage } from './index';

export { dateTime } from './index';

export function t(key: string, values?: Record<string, string | number>): string {
    return adminAssetLabel(clientI18n.t(key, { ...values, ns: 'admin' }));
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
    // Feedback components may pass already translated copy to the result dialog.
    // Accept only our catalog values, never arbitrary server/provider text.
    const catalog = clientI18n.getResourceBundle(clientI18n.language, 'admin') ?? {};
    if (Object.values(catalog).includes(message)) return message;
    for (const [pattern, key] of adminValidationMessages) {
        if (pattern.test(message)) return t(key);
    }
    return adminAssetLabel(commonErrorMessage(message) ?? message);
}

const adminValidationMessages: [RegExp, string][] = [
    [
        /^The email field format is invalid\.$/,
        'Login account must not contain spaces or control characters.',
    ],
    [
        /^The password field must contain at least one letter\.$/,
        'Password must contain at least one letter.',
    ],
    [
        /^The password field must contain at least one uppercase and one lowercase letter\.$/,
        'Password must contain both uppercase and lowercase letters.',
    ],
    [
        /^The password field must contain at least one number\.$/,
        'Password must contain at least one number.',
    ],
    [
        /^The password field must contain at least one symbol\.$/,
        'Password must contain at least one symbol.',
    ],
    [/^The password field confirmation does not match\.$/, 'The two passwords do not match.'],
    [
        /^The password field must be at least 6 characters\.$/,
        'Administrator password must contain at least 6 characters.',
    ],
    [
        /^The password field must not be greater than 72 characters\.$/,
        'Administrator password must not exceed 72 characters.',
    ],
    [/^The email has already been taken\.$/, 'This email address is already in use.'],
];
