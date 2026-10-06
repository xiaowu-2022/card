import {
    invitationLocation,
    initialSelection,
    type Kind,
    type Selection,
} from './partner-invitations-state';
import { Fragment, useEffect, useRef, useState } from 'react';
import { readEditorResponse } from './editor-response';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableHead,
    TableHeader,
    TableBody,
    TableRow,
    TableCell,
} from '@/components/ui/table';
import { t } from '@/i18n/admin';
import { clientI18n } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
import { commissionSum } from '@/lib/paid-promotion';

type Cell = { count: number; amount: string; minimum: string; maximum: string };
type Report = {
    account: {
        companyName: string;
        accountId: string;
        displayName: string | null;
        email: string | null;
    };
    timezone: string;
    commission: string;
    summary: {
        tables: Record<Kind, { rank: number; direct: Cell; indirect: Cell }[]>;
        teamByLevel: { rank: number; direct: number; indirect: number }[];
        registeredMembers?: { direct: number; indirect: number };
        directPeople: number;
        indirectPeople: number;
    };
    details: null | {
        kind: Kind;
        rank: number;
        page: number;
        hasMore: boolean;
        items: {
            id: string;
            accountId: string;
            displayName: string | null;
            email: string | null;
            direct: boolean;
            sourceAmount: string;
            rate: string;
            amount: string;
            occurredAt: string;
        }[];
    };
};
const rankName = (rank: number) =>
    rank ? t('Mastercard level {{rank}}', { rank }) : t('Ordinary member');
const kindName = (kind: Kind) =>
    t(kind === 'ANNUAL' ? 'Annual fee commission' : 'Activation commission');
export function PartnerInvitationsReport({
    partner,
    company,
}: {
    partner: string;
    company: string | null;
}) {
    const [selection, setSelection] = useState<Selection>(initialSelection);
    const [report, setReport] = useState<Report | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    const [expanded, setExpanded] = useState<number | null>(null);
    const body = useRef<HTMLDivElement>(null);
    useEffect(() => {
        const pop = () => setSelection(initialSelection());
        window.addEventListener('popstate', pop);
        return () => window.removeEventListener('popstate', pop);
    }, []);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        setReport(null);
        const query = new URLSearchParams(company ? { company } : {});
        if (selection) {
            query.set('kind', selection.kind);
            query.set('rank', String(selection.rank));
            query.set('page', String(selection.page));
        }
        void fetch(`/platform/partners/${encodeURIComponent(partner)}/invitations?${query}`, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: controller.signal,
            headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
        })
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.account || !data.summary)
                    throw new Error(t('Unable to load. Please retry.'));
                if (!controller.signal.aborted) {
                    setReport(data as Report);
                    if (body.current) body.current.scrollTop = 0;
                }
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(t('Unable to load. Please retry.'));
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [partner, company, selection, retry]);
    const visit = (next: Selection) => {
        invitationLocation(partner, next);
        setSelection(next);
    };
    return (
        <div
            ref={body}
            data-invitation-body
            scroll-region="true"
            className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4"
            aria-busy={loading}
        >
            {loading ? (
                <p role="status">{t('Loading…')}</p>
            ) : error ? (
                <div role="alert" className="space-y-3">
                    <p>{error}</p>
                    <Button onClick={() => setRetry((value) => value + 1)}>{t('Retry')}</Button>
                </div>
            ) : (
                report && (
                    <>
                        {report.details ? (
                            <>
                                <Button variant="secondary" onClick={() => visit(null)}>
                                    {t('Back to invitation data')}
                                </Button>
                                <h3 className="font-semibold">
                                    {rankName(report.details.rank)} ·{' '}
                                    {kindName(report.details.kind)}
                                </h3>
                                <p className="text-sm text-muted-foreground">
                                    {t('Amounts in USDT')} · {report.timezone}
                                </p>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {[
                                                'Source user',
                                                'Relationship at award',
                                                'Source amount',
                                                'Reward rate / difference',
                                                'Commission amount',
                                                'Time',
                                            ].map((label) => (
                                                <TableHead key={label}>{t(label)}</TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {report.details.items.map((row) => (
                                            <TableRow key={row.id}>
                                                <TableCell>
                                                    <strong>{row.displayName || '—'}</strong>
                                                    <p className="max-w-64 break-all text-xs text-muted-foreground">
                                                        {row.email}
                                                    </p>
                                                </TableCell>
                                                <TableCell>
                                                    {t(row.direct ? 'Direct' : 'Indirect')}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {exactAmount(row.sourceAmount)}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {exactAmount(row.rate)}{' '}
                                                    {report.details?.kind === 'ANNUAL'
                                                        ? '%'
                                                        : 'USDT'}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {exactAmount(row.amount)}
                                                </TableCell>
                                                <TableCell className="whitespace-nowrap">
                                                    {new Intl.DateTimeFormat(clientI18n.language, {
                                                        timeZone: report.timezone,
                                                        dateStyle: 'medium',
                                                        timeStyle: 'short',
                                                    }).format(new Date(row.occurredAt))}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                                {!report.details.items.length && <p>{t('No matching records.')}</p>}
                                <div className="flex items-center justify-between gap-3">
                                    <Button
                                        variant="secondary"
                                        disabled={report.details.page <= 1}
                                        onClick={() =>
                                            visit({
                                                ...selection!,
                                                page: report.details!.page - 1,
                                            })
                                        }
                                    >
                                        {t('Previous')}
                                    </Button>
                                    <span>{report.details.page}</span>
                                    <Button
                                        variant="secondary"
                                        disabled={!report.details.hasMore}
                                        onClick={() =>
                                            visit({
                                                ...selection!,
                                                page: report.details!.page + 1,
                                            })
                                        }
                                    >
                                        {t('Next')}
                                    </Button>
                                </div>
                            </>
                        ) : (
                            <>
                                <div className="rounded-xl border bg-muted/30 p-4">
                                    <p>{t('Cumulative commission')}</p>
                                    <strong className="text-2xl">
                                        {exactAmount(report.commission)} USDT
                                    </strong>
                                </div>
                                <p className="text-sm">
                                    {t('Direct members')}: {report.summary.directPeople} ·{' '}
                                    {t('Indirect members')}: {report.summary.indirectPeople} ·{' '}
                                    {t('Amounts in USDT')}
                                </p>
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            {[
                                                'Level',
                                                'Direct members',
                                                'Indirect members',
                                                'Annual fee commission',
                                                'Activation commission',
                                            ].map((label) => (
                                                <TableHead key={label}>{t(label)}</TableHead>
                                            ))}
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        <TableRow>
                                            <TableCell>{t('Registered member')}</TableCell>
                                            <TableCell>
                                                {report.summary.registeredMembers?.direct ?? 0}
                                            </TableCell>
                                            <TableCell>
                                                {report.summary.registeredMembers?.indirect ?? 0}
                                            </TableCell>
                                            <TableCell>—</TableCell>
                                            <TableCell>—</TableCell>
                                        </TableRow>
                                        {report.summary.tables.ACTIVATION.map((activation) => {
                                            const annual = report.summary.tables.ANNUAL.find(
                                                (row) => row.rank === activation.rank,
                                            )!;
                                            const people = report.summary.teamByLevel.find(
                                                (row) => row.rank === activation.rank,
                                            )!;
                                            const rank = activation.rank;
                                            return (
                                                <Fragment key={rank}>
                                                    <TableRow>
                                                        <TableCell>
                                                            <button
                                                                className="text-primary underline underline-offset-4"
                                                                aria-expanded={expanded === rank}
                                                                onClick={() =>
                                                                    setExpanded(
                                                                        expanded === rank
                                                                            ? null
                                                                            : rank,
                                                                    )
                                                                }
                                                            >
                                                                {rankName(rank)}{' '}
                                                                {expanded === rank ? '−' : '+'}
                                                            </button>
                                                        </TableCell>
                                                        <TableCell>{people.direct}</TableCell>
                                                        <TableCell>{people.indirect}</TableCell>
                                                        {(
                                                            [
                                                                ['ANNUAL', annual],
                                                                ['ACTIVATION', activation],
                                                            ] as const
                                                        ).map(([kind, cell]) => {
                                                            return (
                                                                <TableCell key={kind}>
                                                                    {kind === 'ANNUAL' && !rank ? (
                                                                        '—'
                                                                    ) : (
                                                                        <button
                                                                            className="text-primary underline underline-offset-4"
                                                                            onClick={() =>
                                                                                visit({
                                                                                    kind,
                                                                                    rank,
                                                                                    page: 1,
                                                                                })
                                                                            }
                                                                        >
                                                                            {exactAmount(
                                                                                commissionSum(
                                                                                    cell.direct
                                                                                        .amount,
                                                                                    cell.indirect
                                                                                        .amount,
                                                                                ),
                                                                            )}
                                                                        </button>
                                                                    )}
                                                                </TableCell>
                                                            );
                                                        })}
                                                    </TableRow>
                                                    {expanded === rank && (
                                                        <TableRow>
                                                            <TableCell colSpan={5}>
                                                                <div className="grid gap-4 rounded-lg bg-muted/40 p-4 md:grid-cols-2">
                                                                    {(
                                                                        [
                                                                            'ANNUAL',
                                                                            'ACTIVATION',
                                                                        ] as Kind[]
                                                                    ).map((kind) => {
                                                                        const row =
                                                                            kind === 'ANNUAL'
                                                                                ? annual
                                                                                : activation;
                                                                        return (
                                                                            <section
                                                                                key={kind}
                                                                                className="space-y-2"
                                                                            >
                                                                                <h4 className="font-semibold">
                                                                                    {kindName(kind)}
                                                                                </h4>
                                                                                {kind ===
                                                                                    'ANNUAL' &&
                                                                                !rank ? (
                                                                                    <p>
                                                                                        {t(
                                                                                            'Ordinary members do not earn annual fee commission.',
                                                                                        )}
                                                                                    </p>
                                                                                ) : (
                                                                                    <>
                                                                                        {(
                                                                                            [
                                                                                                'direct',
                                                                                                'indirect',
                                                                                            ] as const
                                                                                        ).map(
                                                                                            (
                                                                                                relation,
                                                                                            ) => (
                                                                                                <div
                                                                                                    key={
                                                                                                        relation
                                                                                                    }
                                                                                                    className="text-sm"
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
                                                                                                            'Current members',
                                                                                                        )}

                                                                                                        :{' '}
                                                                                                        {
                                                                                                            people[
                                                                                                                relation
                                                                                                            ]
                                                                                                        }{' '}
                                                                                                        ·{' '}
                                                                                                        {exactAmount(
                                                                                                            row[
                                                                                                                relation
                                                                                                            ]
                                                                                                                .amount,
                                                                                                        )}{' '}
                                                                                                        USDT
                                                                                                    </p>
                                                                                                    {kind ===
                                                                                                        'ACTIVATION' && (
                                                                                                        <p>
                                                                                                            {t(
                                                                                                                'Reward rate / difference',
                                                                                                            )}

                                                                                                            :{' '}
                                                                                                            {row[
                                                                                                                relation
                                                                                                            ]
                                                                                                                .count
                                                                                                                ? `${exactAmount(row[relation].minimum)}–${exactAmount(row[relation].maximum)} USDT`
                                                                                                                : '—'}
                                                                                                        </p>
                                                                                                    )}
                                                                                                </div>
                                                                                            ),
                                                                                        )}
                                                                                        <Button
                                                                                            size="sm"
                                                                                            variant="secondary"
                                                                                            onClick={() =>
                                                                                                visit(
                                                                                                    {
                                                                                                        kind,
                                                                                                        rank,
                                                                                                        page: 1,
                                                                                                    },
                                                                                                )
                                                                                            }
                                                                                        >
                                                                                            {t(
                                                                                                'View records',
                                                                                            )}
                                                                                        </Button>
                                                                                    </>
                                                                                )}
                                                                            </section>
                                                                        );
                                                                    })}
                                                                </div>
                                                            </TableCell>
                                                        </TableRow>
                                                    )}
                                                </Fragment>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Members: current level. Commission: level at the time earned.',
                                    )}
                                </p>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Deposit refunds do not reduce ordinary member counts. Becoming an agent replaces ordinary membership; after agent status ends, a new deposit payment is required.',
                                    )}
                                </p>
                            </>
                        )}
                    </>
                )
            )}
        </div>
    );
}
