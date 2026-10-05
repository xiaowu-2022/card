export type FundsTarget = { company: string; user: string };
export type FundsFilters = { asset: string; event: string; from: string; to: string; page: number };
const keys = ['company', 'user', 'asset', 'event', 'from', 'to', 'page'];
export function initialFundsTarget(): FundsTarget | null {
    const query = new URL(location.href).searchParams;
    const company = query.get('funds_company');
    const user = query.get('funds_user');
    return company && user ? { company, user } : null;
}
export function initialFundsFilters(): FundsFilters {
    const query = new URL(location.href).searchParams;
    const page = Number(query.get('funds_page') ?? 1);
    return {
        asset: query.get('funds_asset') ?? '',
        event: query.get('funds_event') ?? '',
        from: query.get('funds_from') ?? '',
        to: query.get('funds_to') ?? '',
        page: Number.isInteger(page) && page > 0 ? page : 1,
    };
}
export function fundsLocation(target: FundsTarget | null, filters?: FundsFilters) {
    const url = new URL(location.href);
    for (const key of keys) url.searchParams.delete(`funds_${key}`);
    if (target) {
        for (const [key, value] of Object.entries({ ...target, ...filters }))
            if (value) url.searchParams.set(`funds_${key}`, String(value));
    }
    history.replaceState(history.state, '', url);
}
