import type { AccountActivation } from './AssetCenter';
import { useState } from 'react';
import { ChevronDown } from 'lucide-react';
import {
    commissionSum,
    promotionMoney,
    promotionTableAmount,
    promotionLevel,
} from '@/lib/paid-promotion';
import { Link } from '@inertiajs/react';
import { t, dateTime } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';

export type PaidLevel = {
    selectable?: boolean;
    unavailableReason?: string | null;
    id: string;
    rank: number;
    fee: string;
    percent: number;
    reward: string;
    target: number;
    revision: number;
    enabled: boolean;
};
type Cell = { count: number; amount: string; minimum: string; maximum: string };
export type PaidClaim = {
    id: string;
    rank: number;
    amount: string;
    status: string;
    target: number;
    direct: number;
    indirect: number;
    createdAt: string;
    processedAt: string | null;
    source: 'AUTO';
};
export type PaidPromotionData = {
    upgradeEligibility: {
        weightedUnits: number;
        weightedCount: string;
        highestEnabledRank: number;
        pending: boolean;
    };
    activation: AccountActivation;
    paymentAccess: { verified: boolean; walletActive: boolean; canCreateWallet: boolean };
    membershipStatus: 'NONE' | 'ACTIVE' | 'EXPIRED';
    previousCycle: { rank: number; endsAt: string } | null;
    availableBalance: string;
    levels: PaidLevel[];
    rank: number;
    percent: number;
    reward: string;
    cycle: {
        id: string;
        startsAt: string;
        endsAt: string;
        tariff: string;
        rebatePolicy: 'AUTO_FIRST_FUNDING';
    } | null;
    progress: {
        policy: 'AUTO_FIRST_FUNDING';
        pending: boolean;
        direct: number;
        indirect: number;
        target: number;
        paid: string;
        returned: string;
        remaining: string;
    } | null;
    claimsPage: number;
    hasMoreClaims: boolean;
    pending: boolean;
    claims: PaidClaim[];
    tables: Record<'ANNUAL' | 'ACTIVATION', { rank: number; direct: Cell; indirect: Cell }[]>;
    totals: Record<'ANNUAL' | 'ACTIVATION', string>;
    legacy: string;
    teamByLevel: { rank: number; direct: number; indirect: number }[];
    directPeople: number;
    indirectPeople: number;
};
const price = (cell: Cell) =>
    cell.minimum === cell.maximum
        ? exactAmount(cell.minimum)
        : `${exactAmount(cell.minimum)}–${exactAmount(cell.maximum)}`;

export function PaidPromotionMembership({ paid: p }: { paid: PaidPromotionData }) {
    const action =
        p.membershipStatus !== 'EXPIRED' &&
        p.rank > 0 &&
        !p.levels.some((level) => level.enabled && level.rank > p.rank)
            ? 'View benefits'
            : p.membershipStatus === 'EXPIRED'
              ? 'Renew level'
              : p.rank > 0
                ? 'Upgrade level'
                : 'Apply for level';
    return (
        <section aria-labelledby="membership-title" className="rounded-xl bg-[#faf5e7] px-4 py-3">
            <div className="flex items-center justify-between gap-3">
                <h2
                    id="membership-title"
                    className="min-w-0 flex-1 break-words text-sm font-semibold"
                >
                    <span className="text-xs font-normal text-muted-foreground">
                        {t('My level')}:{' '}
                    </span>
                    {promotionLevel(p.rank)}
                </h2>
                <Link
                    href="/promotion/membership"
                    className="flex min-h-11 max-w-[42%] shrink-0 items-center justify-center rounded-full border border-[#a58c4c] px-3 py-2 text-center text-xs font-medium text-[#254235]"
                >
                    {t(action)}
                </Link>
            </div>
            {p.cycle && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {t('Valid until {{time}}', { time: dateTime(p.cycle.endsAt) })}
                </p>
            )}
            {p.membershipStatus === 'EXPIRED' && p.previousCycle && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {t(
                        'Your previous level expired on {{time}}. Ordinary member rewards now apply.',
                        { time: dateTime(p.previousCycle.endsAt) },
                    )}
                </p>
            )}
        </section>
    );
}

export function PaidPromotionSummary({ paid: p }: { paid: PaidPromotionData }) {
    const [showAll, setShowAll] = useState(true);
    const [expanded, setExpanded] = useState<number | null>(null);
    const rows = p.tables.ACTIVATION.map((activation) => {
        const annual = p.tables.ANNUAL.find((row) => row.rank === activation.rank)!;
        const people = p.teamByLevel.find((row) => row.rank === activation.rank)!;
        return {
            rank: activation.rank,
            directPeople: people.direct,
            indirectPeople: people.indirect,
            annual,
            activation,
            annualAmount: commissionSum(annual.direct.amount, annual.indirect.amount),
            activationAmount: commissionSum(activation.direct.amount, activation.indirect.amount),
            hasRecords: [
                annual.direct,
                annual.indirect,
                activation.direct,
                activation.indirect,
            ].some((c) => c.count > 0 || !/^0(?:\.0+)?$/.test(c.amount)),
        };
    });
    const visible = rows.filter(
        (row) => showAll || row.hasRecords || row.directPeople > 0 || row.indirectPeople > 0,
    );
    return (
        <section
            className="min-w-0 rounded-2xl border border-border/60 bg-surface px-3 py-4 sm:p-5"
            id="team-summary"
        >
            <div className="mb-3 flex items-center justify-between gap-2">
                <h2 className="min-w-0 text-base font-semibold">{t('Reward breakdown')}</h2>
                <button
                    className="ml-auto min-h-11 min-w-0 text-xs underline"
                    onClick={() => setShowAll(!showAll)}
                    aria-expanded={showAll}
                >
                    {t(showAll ? 'Show populated levels' : 'Show all levels')}
                </button>
                <p className="shrink-0 whitespace-nowrap text-[11px] text-muted-foreground">
                    {t('Amounts in USDT')}
                </p>
            </div>
            <table className="w-full table-fixed text-right text-[11px] sm:text-sm">
                <caption className="sr-only">{t('Team members and commissions by level')}</caption>
                <colgroup>
                    <col className="w-[24%]" />
                    <col className="w-[13%]" />
                    <col className="w-[13%]" />
                    <col className="w-[25%]" />
                    <col className="w-[25%]" />
                </colgroup>
                <thead>
                    <tr className="border-b">
                        {[
                            'Level',
                            'Direct members',
                            'Indirect members',
                            'Annual fee commission',
                            'Activation commission',
                        ].map((label) => (
                            <th
                                key={label}
                                scope="col"
                                className="break-words whitespace-pre-line px-1 py-3 align-bottom font-medium first:text-left"
                            >
                                {t(label)}
                            </th>
                        ))}
                    </tr>
                </thead>
                {visible.map((row) => (
                    <tbody key={row.rank} data-reward-rank={row.rank}>
                        <tr className="border-b">
                            <th scope="row" className="px-1 py-1 text-left font-normal">
                                <button
                                    onClick={() =>
                                        setExpanded(expanded === row.rank ? null : row.rank)
                                    }
                                    aria-expanded={expanded === row.rank}
                                    aria-controls={`reward-detail-${row.rank}`}
                                    className="flex min-h-12 w-full items-center justify-between gap-1 py-2 text-left"
                                >
                                    <span className="min-w-0 break-words">
                                        {promotionLevel(row.rank)}
                                    </span>
                                    <ChevronDown
                                        className={`size-3 shrink-0 ${expanded === row.rank ? 'rotate-180' : ''}`}
                                    />
                                </button>
                            </th>
                            <td className="break-all px-1 py-3 tabular-nums">{row.directPeople}</td>
                            <td className="break-all px-1 py-3 tabular-nums">
                                {row.indirectPeople}
                            </td>
                            <td className="break-all px-1 py-3 tabular-nums">
                                {row.rank ? promotionTableAmount(row.annualAmount) : '—'}
                            </td>
                            <td className="break-all px-1 py-3 tabular-nums">
                                {promotionTableAmount(row.activationAmount)}
                            </td>
                        </tr>
                        <tr hidden={expanded !== row.rank} id={`reward-detail-${row.rank}`}>
                            <td colSpan={5} className="pb-4 text-left">
                                <div className="mt-4 space-y-5 rounded-xl bg-muted/40 p-3">
                                    {(['ANNUAL', 'ACTIVATION'] as const).map((kind) => {
                                        const annual = kind === 'ANNUAL';
                                        const data = annual ? row.annual : row.activation;
                                        return (
                                            <section key={kind} className="space-y-3">
                                                <h4 className="text-sm font-medium">
                                                    {t(
                                                        annual
                                                            ? 'Annual fee commission'
                                                            : 'Activation commission',
                                                    )}
                                                </h4>
                                                {annual && row.rank === 0 ? (
                                                    <p className="text-xs text-muted-foreground">
                                                        {t(
                                                            'Ordinary members do not earn annual fee commission.',
                                                        )}
                                                    </p>
                                                ) : (
                                                    <>
                                                        {(['direct', 'indirect'] as const).map(
                                                            (relation) => {
                                                                const cell = data[relation];
                                                                return (
                                                                    <div
                                                                        key={relation}
                                                                        className="space-y-1 text-xs"
                                                                    >
                                                                        <p>
                                                                            {t(
                                                                                relation ===
                                                                                    'direct'
                                                                                    ? 'Direct'
                                                                                    : 'Indirect',
                                                                            )}{' '}
                                                                            ·{' '}
                                                                            {t(
                                                                                annual
                                                                                    ? '{{count}} paid orders'
                                                                                    : '{{count}} funding events',
                                                                                {
                                                                                    count: cell.count,
                                                                                },
                                                                            )}
                                                                        </p>
                                                                        {!annual && (
                                                                            <p className="text-muted-foreground">
                                                                                {t(
                                                                                    relation ===
                                                                                        'direct'
                                                                                        ? 'Unit price'
                                                                                        : 'Difference',
                                                                                )}
                                                                                :{' '}
                                                                                {cell.count
                                                                                    ? price(cell) +
                                                                                      ' USDT'
                                                                                    : '—'}
                                                                            </p>
                                                                        )}
                                                                        <p className="break-words font-medium">
                                                                            {promotionMoney(
                                                                                cell.amount,
                                                                            )}
                                                                        </p>
                                                                    </div>
                                                                );
                                                            },
                                                        )}
                                                        <Link
                                                            className="inline-flex min-h-11 items-center text-xs underline"
                                                            href={`/promotion/rewards?kind=${kind}&rank=${row.rank}`}
                                                        >
                                                            {t('View records')}
                                                        </Link>
                                                    </>
                                                )}
                                            </section>
                                        );
                                    })}
                                </div>
                            </td>
                        </tr>
                    </tbody>
                ))}
                {!visible.length && (
                    <tbody>
                        <tr>
                            <td
                                colSpan={5}
                                className="py-6 text-center text-sm text-muted-foreground"
                            >
                                {t('No team members or reward records yet.')}
                            </td>
                        </tr>
                    </tbody>
                )}
            </table>
            <p className="mt-3 text-[11px] leading-5 text-muted-foreground">
                {t('Members: current level. Commission: level at the time earned.')}
            </p>
            <details className="text-xs leading-5 text-muted-foreground">
                <summary className="cursor-pointer py-2">{t('Statistics notes')}</summary>
                <p>
                    {t(
                        'Member counts use current valid levels; commission uses historical event levels. Do not multiply current member counts by current reward rates.',
                    )}
                </p>
                <p>
                    {t('Select a level for actual award records. Ranges reflect historical rates.')}
                </p>
            </details>
        </section>
    );
}
