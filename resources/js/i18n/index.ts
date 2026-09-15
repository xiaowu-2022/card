import i18next from 'i18next';
import { initReactI18next, useTranslation } from 'react-i18next';
import { catalog } from './catalog';
import { clientCopyAliases } from './client-polish-catalog';
import { adminCatalog, adminEnglish } from './admin-catalog';

export const clientResources = Object.fromEntries(
    ['en', 'zh-CN', 'ms', 'es'].map((locale, index) => [
        locale,
        {
            ...(index < 2
                ? {
                      admin: {
                          ...Object.fromEntries(
                              Object.entries(catalog).map(([key, translations]) => [
                                  key,
                                  index === 0 ? key : translations[0],
                              ]),
                          ),
                          ...Object.fromEntries(
                              Object.entries(adminCatalog).map(([key, translation]) => [
                                  key,
                                  index === 0 ? (adminEnglish[key] ?? key) : translation,
                              ]),
                          ),
                      },
                  }
                : {}),
            translation: Object.fromEntries(
                Object.entries(catalog).map(([key, translations]) => [
                    key,
                    index === 0
                        ? (clientCopyAliases[key] ?? key)
                        : (catalog[(clientCopyAliases[key] ?? key) as keyof typeof catalog] ??
                              translations)[index - 1],
                ]),
            ),
        },
    ]),
);

export const clientI18n = i18next.createInstance();
void clientI18n.use(initReactI18next).init({
    resources: clientResources,
    lng: 'en',
    fallbackLng: 'en',
    supportedLngs: ['en', 'zh-CN', 'ms', 'es'],
    keySeparator: false,
    nsSeparator: false,
    initAsync: false,
    interpolation: { escapeValue: false },
});

let displayTimezone = 'UTC';
export function configureClientLocale(locale: string, timezone: string) {
    displayTimezone = timezone;
    void clientI18n.changeLanguage(locale);
    document.documentElement.lang = locale;
}

// Natural-language keys are centralized in complete, parity-checked catalogs.
// Identifiers, custom product names, assets, addresses and monetary values are never translated.
export function t(key: string, values?: Record<string, string | number>): string {
    return clientI18n.t(key, values ?? {});
}
export function useClientTranslation() {
    return useTranslation(undefined, { i18n: clientI18n });
}
export function dateTime(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat(clientI18n.language, {
        timeZone: displayTimezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}

export function errorMessage(message?: string): string | undefined {
    if (!message) return undefined;
    if (clientI18n.exists(message)) return t(message);
    // Translate Laravel validation templates without echoing user-supplied values.
    if (/^The .+ field is required\.$/.test(message)) return t('This field is required.');
    if (/^The .+ field must be a valid email address\.$/.test(message))
        return t('Enter a valid email address.');
    if (/^The .+ field confirmation does not match\.$/.test(message))
        return t('The confirmation does not match.');
    const minimum = message.match(/^The .+ field must be at least (\d+) characters\.$/);
    if (minimum?.[1]) return t('Use at least {{min}} characters.', { min: minimum[1] });
    const maximum = message.match(/^The .+ field must not be greater than (\d+) characters\.$/);
    if (maximum?.[1]) return t('Use no more than {{max}} characters.', { max: maximum[1] });
    if (/^The selected .+ is invalid\.$/.test(message)) return t('The selected value is invalid.');
    if (/^The .+ field format is invalid\.$/.test(message)) return t('The format is invalid.');
    // Never display an untranslated/unrecognized server or provider error in the UI.
    return t('Unable to complete this request. Check your information and current status.');
}
