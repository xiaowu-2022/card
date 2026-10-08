export const companySettings = [
    { value: 'domains', label: 'Domains' },
    { value: 'settings/branding', label: 'Brand and App' },
    { value: 'settings/locales', label: 'Locales' },
    { value: 'settings/business', label: 'Business rules' },
    { value: 'assets', label: 'Asset settings' },
    { value: 'card-products', label: 'Card products' },
    { value: 'promotion', label: 'Promotion' },
    { value: 'wealth', label: 'Wealth settings' },
    { value: 'settings/articles', label: 'About us articles' },
    { value: 'settings/sms', label: 'Aliyun SMS' },
    { value: 'settings/email', label: 'Proton email' },
    { value: 'team', label: 'Admin team' },
    { value: 'support/hours', label: 'Service hours' },
    { value: 'support/replies', label: 'Quick replies' },
    { value: 'support/bot', label: 'Bot and FAQ' },
] as const;
export function allowedCompanySettings(permissions: string[]) {
    return companySettings.filter(({ value }) =>
        value.startsWith('support/')
            ? permissions.includes('support.read') &&
              permissions.includes(`support.${value.split('/')[1]}.manage`)
            : permissions.includes('tenant.manage'),
    );
}
export function companySection(value: string | null): string {
    return companySettings.some((item) => item.value === value) ? value! : 'domains';
}
export function companySettingsUrl(company: string, section: string): string {
    if (section.startsWith('support/'))
        return `/platform/${section}?company=${encodeURIComponent(company)}`;
    return section === 'assets'
        ? `/platform/settings/assets?company=${encodeURIComponent(company)}`
        : `/platform/tenants/${encodeURIComponent(company)}/configuration/${companySection(section)}`;
}
export function companyEditor(target: string | null): { company: string; section: string } | null {
    if (!target) return null;
    try {
        const url = new URL(target, location.origin);
        if (url.origin !== location.origin) return null;
        const company = url.searchParams.get('company');
        if (url.pathname === '/platform/settings/assets' && company)
            return { company, section: 'assets' };
        if (/^\/platform\/support\/(hours|replies|bot)$/.test(url.pathname) && company)
            return { company, section: url.pathname.slice('/platform/'.length) };
        const legacy = /^\/platform\/tenants\/([^/]+)\/domains$/.exec(url.pathname);
        if (legacy) return { company: decodeURIComponent(legacy[1]!), section: 'domains' };
        const match = /^\/platform\/tenants\/([^/]+)\/configuration\/(.+)$/.exec(url.pathname);
        if (!match) return null;
        const section =
            match[2] === 'paid-promotion'
                ? 'promotion'
                : match[2] === 'onboarding'
                  ? 'domains'
                  : match[2] === 'settings'
                    ? 'settings/branding'
                    : match[2]!;
        return companySettings.some((item) => item.value === section) || section === 'settings/kyc'
            ? { company: decodeURIComponent(match[1]!), section }
            : null;
    } catch {
        return null;
    }
}
