import { router } from '@inertiajs/react';
import { t } from '@/i18n';
import { promotionLevel, promotionTableAmount } from '@/lib/paid-promotion';
import { exactAmount } from '@/lib/exact-amount';

export type ReportFilters = Record<string, string | number | null>;
export type ReportPeriod = {
    ranks: number[];
    dateFrom: string | null;
    dateTo: string | null;
    today: string;
    timezone: string;
    presets: Record<string, string>;
};
export type IncomeTotals = { total: string; annual: string; activation: string; legacy: string };
export const incomeLabels: Record<string, string> = {
    annual: 'Annual fee commission',
    activation: 'Activation commission',
    legacy: 'Legacy commission',
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

export function visitReport(url: string, filters: ReportFilters, changes: ReportFilters = {}) {
    const next = { ...filters, ...changes };
    delete next.date;
    delete next.direct_page;
    const query = Object.fromEntries(
        Object.entries(next).filter(
            ([, value]) => value !== null && value !== '' && value !== 'all',
        ),
    );
    router.get(url, query as Record<string, string | number>, {
        preserveState: true,
        preserveScroll: !(Object.keys(changes).length === 1 && changes.page !== undefined),
    });
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
