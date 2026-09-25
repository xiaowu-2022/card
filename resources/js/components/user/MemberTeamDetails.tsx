import { useEffect, useRef, useState } from 'react';
import { Link } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';
import { t, dateTime } from '@/i18n';
import { promotionLevel } from '@/lib/paid-promotion';
import {
    fullMoney,
    incomeLabels,
    memberStatusLabel,
    type MembershipStatus,
    type IncomeTotals,
} from '@/lib/promotion-report';
import {
    Dialog,
    DialogTrigger,
    DialogContent,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Tabs, TabsList, TabsTrigger, TabsContent } from '@/components/ui/tabs';

type TeamSummary = {
    totalMembers: number;
    rows: { rank: number; direct: number; indirect: number; annual: string; activation: string }[];
};
export type MemberData = {
    id: string;
    accountId: string;
    displayName: string | null;
    maskedEmail: string | null;
    rank: number;
    membershipStatus: MembershipStatus;
    joinedAt: string;
    endsAt: string | null;
    totals: IncomeTotals;
};

export function MemberTeamDetails({
    member,
    subject = null,
    beneficiary,
}: {
    member: MemberData;
    subject?: string | null;
    beneficiary: string;
}) {
    const [open, setOpen] = useState(false);
    const [tab, setTab] = useState('income');
    const [summary, setSummary] = useState<TeamSummary | null>(null);
    const [loading, setLoading] = useState(false);
    const [failed, setFailed] = useState(false);
    const request = useRef<AbortController | null>(null);
    useEffect(() => () => request.current?.abort(), []);
    const load = async () => {
        if (summary || request.current) return;
        const controller = new AbortController();
        request.current = controller;
        setLoading(true);
        setFailed(false);
        try {
            const response = await fetch(
                `/promotion/members/${encodeURIComponent(member.id)}/team-summary${subject ? `?subject=${encodeURIComponent(subject)}` : ''}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                },
            );
            if (!response.ok) throw new Error('Unable to load team');
            const data = (await response.json()) as TeamSummary;
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
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (value) setTab('income');
                setOpen(value);
            }}
        >
            <DialogTrigger asChild>
                <button
                    type="button"
                    className="member-data-button"
                    aria-label={t('View data for {{account}}', { account: member.accountId })}
                >
                    {t('View member data')}
                    <ChevronRight size={16} aria-hidden="true" />
                </button>
            </DialogTrigger>
            <DialogContent className="member-data-dialog" closeLabel={t('Close')}>
                <header className="member-data-header">
                    <DialogTitle>
                        {t('Member data')} · {member.accountId}
                    </DialogTitle>
                    <DialogDescription>
                        {t('Commission beneficiary: {{account}}', { account: beneficiary })}
                    </DialogDescription>
                </header>
                <Tabs
                    value={tab}
                    onValueChange={(value) => {
                        setTab(value);
                        if (value === 'team') void load();
                    }}
                    className="member-data-tabs"
                >
                    <TabsList className="member-data-tab-list" aria-label={t('Member data')}>
                        <TabsTrigger value="income">{t('Income breakdown')}</TabsTrigger>
                        <TabsTrigger value="team">{t('Team statistics')}</TabsTrigger>
                    </TabsList>
                    <div className="member-data-scroll">
                        <section
                            className="member-data-identity"
                            aria-label={t('Member information')}
                        >
                            {member.displayName && <strong>{member.displayName}</strong>}
                            {member.maskedEmail && <p>{member.maskedEmail}</p>}
                            <span
                                className={`member-status member-status-${member.membershipStatus}`}
                            >
                                {memberStatusLabel(member.membershipStatus, member.rank)}
                            </span>
                            <p>
                                {t('Joined at')}: {dateTime(member.joinedAt)}
                            </p>
                            {member.endsAt && (
                                <p>
                                    {t('Valid until {{time}}', { time: dateTime(member.endsAt) })}
                                </p>
                            )}
                        </section>
                        <TabsContent value="income" className="member-income-panel">
                            <dl className="member-income-breakdown">
                                <div className="member-income-total">
                                    <dt>{t('Total')}</dt>
                                    <dd>{fullMoney(member.totals.total)}</dd>
                                </div>
                                {Object.entries(incomeLabels).map(([key, label]) => (
                                    <div key={key}>
                                        <dt>{t(label)}</dt>
                                        <dd>
                                            {fullMoney(member.totals[key as keyof IncomeTotals])}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                            <Link
                                className="member-commission-link"
                                href={`/promotion/commissions?${new URLSearchParams({ source_member: member.id, ...(subject ? { subject } : {}) })}`}
                            >
                                {t('View commission records')}
                                <ChevronRight size={16} aria-hidden="true" />
                            </Link>
                        </TabsContent>
                        <TabsContent value="team" className="member-team-panel" aria-busy={loading}>
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
                                            {t('{{count}} team members', {
                                                count: summary.totalMembers,
                                            })}{' '}
                                            · USDT
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
                                                        <th scope="row">
                                                            {promotionLevel(row.rank)}
                                                        </th>
                                                        <td>{row.direct}</td>
                                                        <td>{row.indirect}</td>
                                                        <td>
                                                            {fullMoney(row.annual).replace(
                                                                ' USDT',
                                                                '',
                                                            )}
                                                        </td>
                                                        <td>
                                                            {fullMoney(row.activation).replace(
                                                                ' USDT',
                                                                '',
                                                            )}
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                        <p className="member-team-note">
                                            {t(
                                                'Team counts exclude this member and use current levels. Commissions belong to {{account}} from these descendants, grouped by the source level at the time. Teams may overlap; do not add these summaries together.',
                                                { account: beneficiary },
                                            )}
                                        </p>
                                    </>
                                ))}
                        </TabsContent>
                    </div>
                </Tabs>
            </DialogContent>
        </Dialog>
    );
}
