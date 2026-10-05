export const companySettings = [
    { value: 'assets', label: 'Asset settings' },
    { value: 'settings/branding', label: 'Branding' },
    { value: 'settings/locales', label: 'Locales' },
    { value: 'settings/business', label: 'Business rules' },
    { value: 'settings/articles', label: 'About us articles' },
    { value: 'settings/sms', label: 'Aliyun SMS' },
    { value: 'settings/email', label: 'Proton email' },
    { value: 'promotion', label: 'Promotion' },
    { value: 'wealth', label: 'Wealth settings' },
] as const;
export function companySection(value: string | null): string {
    return companySettings.some((item) => item.value === value) ? value! : 'settings/branding';
}
export function companySettingsUrl(company: string, section: string): string {
    return section === 'assets'
        ? `/platform/settings/assets?company=${encodeURIComponent(company)}`
        : `/platform/tenants/${encodeURIComponent(company)}/configuration/${companySection(section)}`;
}
export function companyEditor(target: string | null): { company: string; section: string } | null {
    if (!target) return null;
    try {
        const url = new URL(target, location.origin);
        if (url.origin !== location.origin) return null;
        if (url.pathname === '/platform/settings/assets' && url.searchParams.get('company'))
            return { company: url.searchParams.get('company')!, section: 'assets' };
        const match =
            /^\/platform\/tenants\/([^/]+)\/configuration\/(settings(?:\/(?:branding|locales|business|articles|sms|email))?|promotion|paid-promotion|wealth)$/.exec(
                url.pathname,
            );
        return match
            ? {
                  company: decodeURIComponent(match[1]!),
                  section: companySection(match[2] === 'paid-promotion' ? 'promotion' : match[2]!),
              }
            : null;
    } catch {
        // Malformed legacy/editor query parameters must not break the background list.
        return null;
    }
}
export const companySectionEvent = 'platform-company-section';
