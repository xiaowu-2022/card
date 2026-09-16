// A back/forward Inertia snapshot may predate a saved language preference.
// Keep only this browser session's confirmed selection, scoped to its owner.
let confirmed: { scope: string; locale: string } | undefined;
export function rememberConfirmedLocale(scope: string, locale: string) {
    confirmed = { scope, locale };
}
export function resolveConfirmedLocale(scope: string, supplied: string, enabled: string[]) {
    if (confirmed?.scope === scope && enabled.includes(confirmed.locale)) return confirmed.locale;
    return supplied;
}
export function consumerLocaleScope(tenantId?: string, userId?: string) {
    return JSON.stringify([tenantId ?? null, userId ?? null]);
}
