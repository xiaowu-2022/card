import company from '../generated/company.json';

export function companyOrigin(): string {
    // H5 follows the domain serving the page; native apps use the configured origin.
    if (import.meta.env.UNI_PLATFORM === 'h5') return window.location.origin;
    return company.apiOrigin.replace(/\/$/, '');
}

export function webBase(): string {
    return import.meta.env.UNI_PLATFORM === 'h5' ? import.meta.env.BASE_URL : '/';
}

export function staticAsset(path: string): string {
    return webBase() + 'static/' + path;
}

export function invitationUrl(code: string): string {
    const path = '/register?invite=' + encodeURIComponent(code);
    if (import.meta.env.UNI_PLATFORM === 'h5')
        return companyOrigin() + webBase() + '#/pages/screen/index?path=' + encodeURIComponent(path);
    return companyOrigin() + path;
}
