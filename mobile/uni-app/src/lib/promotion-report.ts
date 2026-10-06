import { session } from './session';
import { go } from './navigation';
import { t } from './i18n';
import { promotionLevel, promotionTableAmount } from './promotion';
import { exactAmount } from '../generated/exact-amount';

export type ReportFilters = Record<string, string | number | null>;
export type ReportPeriod = {
    ranks: number[];
    dateFrom: string | null;
    dateTo: string | null;
    today: string;
    timezone: string;
    presets: Record<string, string>;
};
export type IncomeTotals = {
    total: string;
    annual: string;
    activation: string;
    legacy: string;
    commission?: string;
};
export const incomeLabels: Record<string, string> = {
    annual: 'Annual fee commission',
    activation: 'Activation commission',
    legacy: 'Legacy commission',
    commission: 'Commission',
};
export const relationLabel = (relation: string) =>
    t(
        (
            {
                direct: 'Direct referral',
                indirect: 'Indirect referral',
                unknown: 'Not recorded',
            } as Record<string, string>
        )[relation] ?? 'Not recorded',
    );
export const reportRank = (rank: number | null | undefined) =>
    rank == null ? t('Historical record · not recorded') : promotionLevel(rank);
export const reportMoney = (amount: string) => `${promotionTableAmount(amount)} USDT`;
export const fullMoney = (amount: string) => `${exactAmount(amount)} USDT`;

function encodeFilterValue(value: string) {
    // Preserve free-text names/emails through route and API path validation.
    return encodeURIComponent(value).replace(
        /[.!'()*]/g,
        (char) => '%' + char.charCodeAt(0).toString(16).toUpperCase(),
    );
}

export function visitReport(url: string, filters: ReportFilters, changes: ReportFilters = {}) {
    const next = { ...filters, ...changes };
    delete next.date;
    delete next.direct_page;
    delete next.scope;
    if (url === '/promotion/direct') delete next.funding;
    const query = Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== null && value !== '' && value !== 'all',
        ),
    );
    go(
        url +
            '?' +
            Object.entries(query)
                .map(
                    ([key, value]) =>
                        encodeURIComponent(key) + '=' + encodeFilterValue(String(value)),
                )
                .join('&'),
        true,
    );
}

export function rankOptions(ranks: number[], unknown = false): [string, string][] {
    return [
        ['all', t('All levels')],
        ...ranks.map((rank): [string, string] => [String(rank), promotionLevel(rank)]),
        ...(unknown
            ? [['unknown', t('Historical record · not recorded')] as [string, string]]
            : []),
    ];
}

export const teamHref = (id?: string | null) =>
    id ? `/promotion/direct?subject=${encodeURIComponent(id)}` : '/promotion/direct';

export type MembershipStatus = 'agent' | 'ordinary' | 'inactive';
export function memberStatusLabel(status: MembershipStatus, rank: number) {
    return status === 'agent'
        ? promotionLevel(rank)
        : t(status === 'ordinary' ? 'Ordinary member' : 'Registered member (not activated)');
}

// Presentation-only navigation memory, isolated by company and signed-in viewer.
let visitScope = '';
const visits = new Map<string, ReportFilters>();
function scopeVisits() {
    const scope = (session.value?.tenant.id ?? '') + ':' + (session.value?.user?.id ?? '');
    if (scope !== visitScope) {
        visits.clear();
        visitScope = scope;
    }
}
export function rememberTeam(filters: ReportFilters) {
    scopeVisits();
    const node = String(filters.subject ?? 'root');
    const saved: ReportFilters = {};
    for (const key of ['account_id', 'rank', 'sort', 'page']) {
        const value = filters[key];
        if (typeof value === 'string' || typeof value === 'number') saved[key] = value;
    }
    visits.delete(node);
    visits.set(node, saved);
    if (visits.size > 100) visits.delete(visits.keys().next().value!);
}
export function teamReturnHref(id?: string | null) {
    scopeVisits();
    const fields = { ...(id ? { subject: id } : {}), ...visits.get(id ?? 'root') };
    const query = Object.entries(fields)
        .filter(([, v]) => v !== null && v !== '' && v !== 'all')
        .map(([k, v]) => encodeURIComponent(k) + '=' + encodeFilterValue(String(v)))
        .join('&');
    return '/promotion/direct' + (query ? '?' + query : '');
}
