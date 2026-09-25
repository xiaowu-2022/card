import { useEffect, useId, useRef, useState } from 'react';
import { ChevronDown } from 'lucide-react';
import { t } from '@/i18n';
import { promotionLevel } from '@/lib/paid-promotion';
import { fullMoney, incomeLabels, type IncomeTotals } from '@/lib/promotion-report';

type TeamSummary = {
    totalMembers: number;
    rows: { rank: number; direct: number; indirect: number; annual: string; activation: string }[];
};

export function MemberTeamDetails({
    memberId,
    totals,
}: {
    memberId: string;
    totals: IncomeTotals;
}) {
    const [incomeOpen, setIncomeOpen] = useState(false);
    const [teamOpen, setTeamOpen] = useState(false);
    const [summary, setSummary] = useState<TeamSummary | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const request = useRef<AbortController | null>(null);
    const id = useId();
    useEffect(() => () => request.current?.abort(), []);
    const load = async () => {
        if (summary || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setFailed(false);
        try {
            const response = await fetch(
                `/promotion/members/${encodeURIComponent(memberId)}/team-summary`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                },
            );
            if (!response.ok) throw new Error('Unable to load team');
            const data: TeamSummary = await response.json();
            if (!Array.isArray(data.rows) || typeof data.totalMembers !== 'number')
                throw new Error('Invalid team response');
            if (!controller.signal.aborted) setSummary(data);
        } catch {
            if (!controller.signal.aborted) setFailed(true);
        } finally {
            request.current = null;
            if (!controller.signal.aborted) setLoading(false);
        }
    };
    return (
        <div className="member-detail-panels">
            <div className="member-detail-toggles">
                <button
                    type="button"
                    aria-expanded={incomeOpen}
                    aria-controls={`${id}-income`}
                    onClick={() => setIncomeOpen(!incomeOpen)}
                >
                    {t('Income breakdown')}
                    <ChevronDown size={13} aria-hidden="true" />
                </button>
                <button
                    type="button"
                    aria-expanded={teamOpen}
                    aria-controls={`${id}-team`}
                    onClick={() => {
                        setTeamOpen(!teamOpen);
                        if (!teamOpen) void load();
                    }}
                >
                    {t('Their team')}
                    <ChevronDown size={13} aria-hidden="true" />
                </button>
            </div>
            <div id={`${id}-income`} hidden={!incomeOpen}>
                <dl className="report-entry-detail">
                    <div>
                        <dt>{t('Total')}</dt>
                        <dd>{fullMoney(totals.total)}</dd>
                    </div>
                    {Object.entries(incomeLabels).map(([key, label]) => (
                        <div key={key}>
                            <dt>{t(label)}</dt>
                            <dd>{fullMoney(totals[key as keyof IncomeTotals])}</dd>
                        </div>
                    ))}
                </dl>
            </div>
            <div
                id={`${id}-team`}
                hidden={!teamOpen}
                className="member-team-panel"
                aria-busy={loading}
            >
                {loading && <p role="status">{t('Loading team…')}</p>}
                {failed && (
                    <div role="alert">
                        <p>{t('Unable to load this team.')}</p>
                        <button
                            type="button"
                            className="min-h-11 underline"
                            onClick={() => void load()}
                        >
                            {t('Retry')}
                        </button>
                    </div>
                )}
                {summary &&
                    (summary.totalMembers === 0 ? (
                        <p>{t('No team members yet.')}</p>
                    ) : (
                        <>
                            <p className="member-team-total">
                                {t('{{count}} team members', { count: summary.totalMembers })} ·
                                USDT
                            </p>
                            <table className="member-team-table">
                                <caption className="sr-only">{t('Their team')}</caption>
                                <colgroup>
                                    <col style={{ width: '24%' }} />
                                    <col style={{ width: '14%' }} />
                                    <col style={{ width: '14%' }} />
                                    <col style={{ width: '24%' }} />
                                    <col style={{ width: '24%' }} />
                                </colgroup>
                                <thead>
                                    <tr>
                                        {[
                                            'Level',
                                            'Direct members',
                                            'Indirect members',
                                            'Annual fee commission',
                                            'Activation commission',
                                        ].map((label) => (
                                            <th key={label} scope="col">
                                                {t(label)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {summary.rows.map((row) => (
                                        <tr key={row.rank}>
                                            <th scope="row">{promotionLevel(row.rank)}</th>
                                            <td>{row.direct}</td>
                                            <td>{row.indirect}</td>
                                            <td>{fullMoney(row.annual).replace(' USDT', '')}</td>
                                            <td>
                                                {fullMoney(row.activation).replace(' USDT', '')}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                            <p className="member-team-note">
                                {t(
                                    'Team counts exclude this member and use current levels. Commissions are your lifetime income from their descendants, grouped by the source level at the time. Teams may overlap; do not add these summaries together.',
                                )}
                            </p>
                        </>
                    ))}
            </div>
        </div>
    );
}
