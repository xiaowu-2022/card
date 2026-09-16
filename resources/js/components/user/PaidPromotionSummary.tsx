import { promotionLevel } from '@/lib/paid-promotion';
import { Link } from '@inertiajs/react';
import { t, dateTime } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';

export type PaidLevel = {
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
    reviewedAt: string | null;
    reason: string | null;
};
export type PaidPromotionData = {
    levels: PaidLevel[];
    rank: number;
    percent: number;
    reward: string;
    cycle: { id: string; startsAt: string; endsAt: string; tariff: string } | null;
    progress: {
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
    directPeople: number;
    indirectPeople: number;
};
const price = (cell: Cell) =>
    cell.minimum === cell.maximum
        ? exactAmount(cell.minimum)
        : `${exactAmount(cell.minimum)}–${exactAmount(cell.maximum)}`;

export function PaidPromotionSummary({ paid: p }: { paid: PaidPromotionData }) {
    return (
        <section className="min-w-0 space-y-5" id="team-summary">
            <div className="rounded-2xl bg-surface p-5">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <h2 className="font-semibold">{promotionLevel(p.rank)}</h2>
                    <Link href="/promotion/membership" className="text-sm underline">
                        {t('Promotion membership')}
                    </Link>
                </div>
                {p.cycle && (
                    <p className="mt-2 text-xs text-muted-foreground">
                        {t('Valid until {{time}}', { time: dateTime(p.cycle.endsAt) })}
                    </p>
                )}
                <dl className="mt-4 grid grid-cols-2 gap-4 text-sm">
                    {[
                        ['Direct team members', p.directPeople],
                        ['Indirect team members', p.indirectPeople],
                        ['My annual fee commission', `${exactAmount(p.totals.ANNUAL)} USDT`],
                        ['My activation commission', `${exactAmount(p.totals.ACTIVATION)} USDT`],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-muted-foreground">{t(String(label))}</dt>
                            <dd className="mt-1 break-words text-base font-semibold">{value}</dd>
                        </div>
                    ))}
                </dl>
                {p.legacy !== '0' && !/^0(?:\.0+)?$/.test(p.legacy) && (
                    <p className="mt-3 text-xs">
                        {t('Legacy commission')}: {exactAmount(p.legacy)} USDT
                    </p>
                )}
            </div>
            {(['ANNUAL', 'ACTIVATION'] as const).map((kind) => (
                <section className="min-w-0 rounded-2xl bg-surface p-4" key={kind}>
                    <h3 className="font-semibold">
                        {t(
                            kind === 'ANNUAL'
                                ? 'Annual fee commission details'
                                : 'Activation commission details',
                        )}
                    </h3>
                    <p className="my-2 text-xs text-muted-foreground">
                        {kind === 'ANNUAL'
                            ? t('Annual fee reward rate: {{rate}}%', { rate: p.percent })
                            : t('Direct activation reward: {{amount}} USDT per event', {
                                  amount: p.reward,
                              })}
                    </p>
                    <div
                        className="max-w-full overflow-x-auto"
                        tabIndex={0}
                        aria-label={t(
                            kind === 'ANNUAL'
                                ? 'Annual fee commission details'
                                : 'Activation commission details',
                        )}
                    >
                        <table className="w-full min-w-[410px] text-right text-xs">
                            <thead>
                                <tr className="border-b">
                                    {(kind === 'ANNUAL'
                                        ? [
                                              'Level',
                                              'Direct',
                                              'Commission',
                                              'Indirect',
                                              'Commission',
                                          ]
                                        : [
                                              'Level',
                                              'Direct',
                                              'Unit price',
                                              'Commission',
                                              'Indirect',
                                              'Difference',
                                              'Commission',
                                          ]
                                    ).map((label, i) => (
                                        <th
                                            className="whitespace-nowrap px-2 py-3 font-medium first:text-left"
                                            key={i}
                                        >
                                            {t(label)}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {p.tables[kind]
                                    .filter((row) => kind === 'ACTIVATION' || row.rank > 0)
                                    .map((row) => (
                                        <tr key={row.rank} className="border-b last:border-0">
                                            <td className="whitespace-nowrap px-2 py-3 text-left">
                                                <Link
                                                    className="underline"
                                                    href={`/promotion/rewards?kind=${kind}&rank=${row.rank}`}
                                                >
                                                    {promotionLevel(row.rank)}
                                                </Link>
                                            </td>
                                            <td className="px-2">{row.direct.count}</td>
                                            {kind === 'ACTIVATION' && (
                                                <td className="px-2">{price(row.direct)}</td>
                                            )}
                                            <td className="px-2">
                                                {exactAmount(row.direct.amount)}
                                            </td>
                                            <td className="px-2">{row.indirect.count}</td>
                                            {kind === 'ACTIVATION' && (
                                                <td className="px-2">{price(row.indirect)}</td>
                                            )}
                                            <td className="px-2">
                                                {exactAmount(row.indirect.amount)}
                                            </td>
                                        </tr>
                                    ))}
                            </tbody>
                        </table>
                    </div>
                    <p className="mt-3 text-xs leading-5 text-muted-foreground">
                        {t(
                            kind === 'ANNUAL'
                                ? 'Counts are paid orders, including renewal and upgrades. Amounts are USDT.'
                                : 'Counts are successful deposit funding events, including re-funding. Amounts are USDT.',
                        )}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {t(
                            'Select a level for actual award records. Ranges reflect historical rates.',
                        )}
                    </p>
                </section>
            ))}
        </section>
    );
}
