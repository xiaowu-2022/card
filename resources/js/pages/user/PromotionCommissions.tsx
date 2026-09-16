import { useEffect, useState } from 'react';
import { Head } from '@inertiajs/react';
import { ArrowDownLeft, ArrowUpRight, ChevronDown, ReceiptText } from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { Input } from '@/components/ui/input';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
import {
    ReportDateButton,
    ReportFilterPanel,
    ReportFilterChip,
    ReportSelect,
    ReportSummary,
    ReportPagination,
} from '@/components/user/PromotionReportControls';
import {
    rankOptions,
    reportMoney,
    fullMoney,
    reportRank,
    relationLabel,
    visitReport,
    incomeLabels,
    type ReportPeriod,
    type ReportFilters,
    type IncomeTotals,
} from '@/lib/promotion-report';
import '../../../css/promotion.css';

type History = ReportPeriod & {
    filters: ReportFilters;
    tab: 'income' | 'transfers';
    page: number;
    hasMore: boolean;
    totals: IncomeTotals;
    items: {
        id: string;
        kind: string;
        amount: string;
        occurredAt: string;
        sourceAccountId?: string;
        sourceRank?: number | null;
        beneficiaryRank?: number | null;
        relation?: string;
        sourceAmount?: string;
        rate?: string | null;
        standard?: number | null;
        covered?: number | null;
        businessAt?: string;
    }[];
};

export default function PromotionCommissions({ history: h }: { history: History }) {
    useClientTranslation();
    const url = '/promotion/commissions';
    const income = h.tab === 'income';
    const filters = { ...h.filters, tab: h.tab, date_from: h.dateFrom, date_to: h.dateTo };
    const filter = (values: ReportFilters) => visitReport(url, filters, { ...values, page: 1 });
    const { account_id = '', kind = 'all', rank = 'all', relation = 'all' } = h.filters;
    const [draft, setDraft] = useState({ account_id, kind, rank, relation });
    useEffect(
        () => setDraft({ account_id, kind, rank, relation }),
        [account_id, kind, rank, relation],
    );
    const active = Object.entries({ account_id, kind, rank, relation }).filter(
        ([, v]) => v && v !== 'all',
    );
    const chips: Record<string, string> = {
        account_id: `${t('Source account')}: ${account_id}`,
        kind: t(incomeLabels[String(kind)] ?? 'All income'),
        rank: rank === 'unknown' ? t('Historical record · not recorded') : reportRank(Number(rank)),
        relation: relationLabel(String(relation)),
    };
    return (
        <UserLayout>
            <Head title={t('Commission details')} />
            <div className="promotion-page promotion-report-page">
                <UserPageHeader title={t('Commission details')} backHref="/promotion/invitations" />
                <nav className="report-tabs" aria-label={t('Commission records')}>
                    {(
                        [
                            ['income', 'Commission income'],
                            ['transfers', 'Transfers to balance'],
                        ] as const
                    ).map(([tab, label]) => (
                        <button
                            key={tab}
                            type="button"
                            aria-current={h.tab === tab ? 'page' : undefined}
                            onClick={() =>
                                visitReport(url, { tab, date_from: h.dateFrom, date_to: h.dateTo })
                            }
                        >
                            {t(label)}
                        </button>
                    ))}
                </nav>
                {income ? (
                    <ReportSummary totals={h.totals} title={t('Commission income')} />
                ) : (
                    <section className="report-income">
                        <p className="report-eyebrow">
                            {t('Total transferred to balance')} <span>USDT</span>
                        </p>
                        <details className="report-total">
                            <summary aria-label={t('Exact amounts')}>
                                {reportMoney(h.totals.total).replace(' USDT', '')}
                            </summary>
                            <p className="report-exact">{fullMoney(h.totals.total)}</p>
                        </details>
                        <p className="report-caption">
                            {t('Available in your wallet after transfer.')}
                        </p>
                    </section>
                )}
                <div className="report-toolbar">
                    <ReportDateButton
                        period={h}
                        allowAll
                        onChange={(from, to) => filter({ date_from: from, date_to: to })}
                    />
                    {income && (
                        <ReportFilterPanel
                            count={active.length}
                            onApply={() => filter(draft)}
                            onReset={() => {
                                setDraft({
                                    account_id: '',
                                    kind: 'all',
                                    rank: 'all',
                                    relation: 'all',
                                });
                                filter({
                                    account_id: '',
                                    kind: 'all',
                                    rank: 'all',
                                    relation: 'all',
                                });
                            }}
                        >
                            <label className="report-field">
                                {t('Source account ID')}
                                <Input
                                    inputMode="numeric"
                                    maxLength={24}
                                    value={String(draft.account_id)}
                                    placeholder={t('Search account ID')}
                                    onChange={(e) =>
                                        setDraft({
                                            ...draft,
                                            account_id: e.target.value.replace(/\D/g, ''),
                                        })
                                    }
                                />
                            </label>
                            <ReportSelect
                                label={t('Commission type')}
                                value={String(draft.kind)}
                                options={[
                                    ['all', t('All income')],
                                    ...Object.entries(incomeLabels).map(
                                        ([key, label]): [string, string] => [key, t(label)],
                                    ),
                                ]}
                                onChange={(kind) => setDraft({ ...draft, kind })}
                            />
                            <ReportSelect
                                label={t('Source level at event')}
                                value={String(draft.rank)}
                                options={rankOptions(true)}
                                onChange={(rank) => setDraft({ ...draft, rank })}
                            />
                            <ReportSelect
                                label={t('Referral relationship')}
                                value={String(draft.relation)}
                                options={[
                                    ['all', t('All')],
                                    ['direct', relationLabel('direct')],
                                    ['indirect', relationLabel('indirect')],
                                    ['unknown', t('Historical record · not recorded')],
                                ]}
                                onChange={(relation) => setDraft({ ...draft, relation })}
                            />
                        </ReportFilterPanel>
                    )}
                </div>
                {income && active.length > 0 && (
                    <div className="report-chips">
                        {active.map(([key]) => (
                            <ReportFilterChip
                                key={key}
                                label={chips[key]!}
                                onClear={() => filter({ [key]: key === 'account_id' ? '' : 'all' })}
                            />
                        ))}
                    </div>
                )}
                {!h.items.length && (
                    <div className="report-empty" role="status">
                        <ReceiptText size={26} strokeWidth={1.4} aria-hidden="true" />
                        <p>{t('No matching records.')}</p>
                    </div>
                )}
                <div className="report-entries">
                    {h.items.map((row) => (
                        <details key={row.id} className="report-entry">
                            <summary className="report-entry-summary">
                                <span className="report-entry-icon" aria-hidden="true">
                                    {income ? (
                                        <ArrowDownLeft size={18} />
                                    ) : (
                                        <ArrowUpRight size={18} />
                                    )}
                                </span>
                                <div className="report-entry-body">
                                    <div className="report-entry-top">
                                        <h2>
                                            {t(
                                                income
                                                    ? (incomeLabels[row.kind] ??
                                                          'Legacy commission')
                                                    : 'Commission transferred to balance',
                                            )}
                                        </h2>
                                        <strong className={income ? 'report-positive' : ''}>
                                            {income ? '+' : ''}
                                            {reportMoney(row.amount)}
                                        </strong>
                                    </div>
                                    {income && (
                                        <div className="report-entry-meta">
                                            <span className="report-account">
                                                {row.sourceAccountId}
                                            </span>
                                            <span>{reportRank(row.sourceRank)}</span>
                                        </div>
                                    )}
                                    <div className="report-entry-meta">
                                        <time>{dateTime(row.occurredAt)}</time>
                                        {income && row.relation !== 'unknown' && (
                                            <span>{relationLabel(row.relation ?? 'unknown')}</span>
                                        )}
                                        <ChevronDown
                                            className="report-chevron"
                                            size={14}
                                            aria-hidden="true"
                                        />
                                    </div>
                                </div>
                                <span className="sr-only">{t('View details')}</span>
                            </summary>
                            <dl className="report-entry-detail">
                                <div>
                                    <dt>
                                        {t(income ? 'Exact commission amount' : 'Transfer amount')}
                                    </dt>
                                    <dd>{fullMoney(row.amount)}</dd>
                                </div>
                                {income && (
                                    <>
                                        <div>
                                            <dt>{t('Source account')}</dt>
                                            <dd>{row.sourceAccountId}</dd>
                                        </div>
                                        <div>
                                            <dt>{t('Referral relationship')}</dt>
                                            <dd>{relationLabel(row.relation ?? 'unknown')}</dd>
                                        </div>
                                        <div>
                                            <dt>{t('My level at settlement')}</dt>
                                            <dd>{reportRank(row.beneficiaryRank)}</dd>
                                        </div>
                                        <div>
                                            <dt>{t('Source amount')}</dt>
                                            <dd>
                                                {row.sourceAmount == null
                                                    ? t('Not recorded')
                                                    : fullMoney(row.sourceAmount)}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt>
                                                {t(
                                                    row.kind === 'annual'
                                                        ? 'Applied commission rate'
                                                        : 'Applied reward difference',
                                                )}
                                            </dt>
                                            <dd>
                                                {row.rate == null
                                                    ? t('Not recorded')
                                                    : `${exactAmount(row.rate)} ${row.kind === 'annual' ? '%' : 'USDT'}`}
                                            </dd>
                                        </div>
                                        {row.standard != null && (
                                            <>
                                                <div>
                                                    <dt>{t('Settlement standard')}</dt>
                                                    <dd>
                                                        {row.standard}{' '}
                                                        {row.kind === 'annual' ? '%' : 'USDT'}
                                                    </dd>
                                                </div>
                                                <div>
                                                    <dt>{t('Already covered')}</dt>
                                                    <dd>
                                                        {row.covered}{' '}
                                                        {row.kind === 'annual' ? '%' : 'USDT'}
                                                    </dd>
                                                </div>
                                            </>
                                        )}
                                        {row.businessAt && (
                                            <div>
                                                <dt>{t('Business event time')}</dt>
                                                <dd>{dateTime(row.businessAt)}</dd>
                                            </div>
                                        )}
                                    </>
                                )}
                                <div>
                                    <dt>{t(income ? 'Credited at' : 'Transferred at')}</dt>
                                    <dd>{dateTime(row.occurredAt)}</dd>
                                </div>
                            </dl>
                        </details>
                    ))}
                </div>
                <ReportPagination
                    page={h.page}
                    hasMore={h.hasMore}
                    onPage={(page) => visitReport(url, filters, { page })}
                />
                <details className="report-notes">
                    <summary>{t('Statistics notes')}</summary>
                    <p>
                        {t(
                            income
                                ? 'Income totals include all matching records. Levels and relationships use settlement snapshots; missing historical details are not inferred.'
                                : 'Transfers move earned commission to your balance and are not new income.',
                        )}
                    </p>
                    <p>{t('Dates and times follow the company timezone.')}</p>
                </details>
            </div>
        </UserLayout>
    );
}
