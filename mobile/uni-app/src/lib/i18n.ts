import { ref } from 'vue';
import { catalog } from '../generated/i18n/catalog';
import { clientCopyAliases } from '../generated/i18n/client-polish-catalog';
export const locale = ref('en');
let timezone = 'UTC';
const entries = catalog as Record<string, readonly string[]>;
const aliases = clientCopyAliases as Record<string, string>;
export function configureLocale(value: string, zone: string) { locale.value = value; timezone = zone; }
export function t(key: string, values: Record<string, string | number> = {}): string {
    const index = ['zh-CN', 'ms', 'es'].indexOf(locale.value);
    const english = aliases[key] ?? key;
    const text = index < 0 ? english : ((entries[english] ?? entries[key])?.[index] ?? english);
    return text.replace(/\{\{(\w+)\}\}/g, (match, name: string) => String(values[name] ?? match));
}
export function dateTime(value: string) {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat(locale.value, { timeZone: timezone, dateStyle: 'medium', timeStyle: 'short' }).format(date);
}
