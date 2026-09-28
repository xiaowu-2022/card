import { ref } from 'vue';
import { catalog } from '../generated/i18n/catalog';
import { clientCopyAliases } from '../generated/i18n/client-polish-catalog';
import { setLanguage } from './api';
export const locale = ref('en');
let timezone = 'UTC';
const entries = catalog as Record<string, readonly string[]>;
const aliases = clientCopyAliases as Record<string, string>;
export function configureLocale(value: string, zone: string) {
    locale.value = value;
    timezone = zone;
    setLanguage(value);
    // Keep uni-app picker controls in the same language as our business copy.
    uni.setLocale(value === 'zh-CN' ? 'zh-Hans' : value);
}
export function changeLocale(value: string) {
    locale.value = value;
    setLanguage(value);
    // Keep uni-app picker controls in the same language as our business copy.
    uni.setLocale(value === 'zh-CN' ? 'zh-Hans' : value);
}
export function errorMessage(
    message?: string,
    fallback = 'Unable to complete this request. Check your information and current status.',
) {
    if (!message) return '';
    if (entries[message] || aliases[message]) return t(message);
    if (/^The .+ field is required\.$/.test(message)) return t('This field is required.');
    if (/^The .+ field must be a valid email address\.$/.test(message))
        return t('Enter a valid email address.');
    if (/^The .+ field confirmation does not match\.$/.test(message))
        return t('The confirmation does not match.');
    const min = message.match(/^The .+ field must be at least (\d+) characters\.$/);
    if (min) return t('Use at least {{min}} characters.', { min: min[1] });
    const max = message.match(/^The .+ field must not be greater than (\d+) characters\.$/);
    if (max) return t('Use no more than {{max}} characters.', { max: max[1] });
    if (/^The selected .+ is invalid\.$/.test(message)) return t('The selected value is invalid.');
    if (/^The .+ field format is invalid\.$/.test(message)) return t('The format is invalid.');
    return t(fallback);
}
export function t(key: string, values: Record<string, string | number> = {}): string {
    const index = ['zh-CN', 'ms', 'es'].indexOf(locale.value);
    const english = aliases[key] ?? key;
    const text = index < 0 ? english : ((entries[english] ?? entries[key])?.[index] ?? english);
    return text.replace(/\{\{(\w+)\}\}/g, (match, name: string) => String(values[name] ?? match));
}
export function dateTime(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date);
}
