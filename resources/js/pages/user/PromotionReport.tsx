import { useEffect, useState } from 'react';
import { Head, Link } from '@inertiajs/react';
import { ChevronDown, ChevronRight, UserPlus, ReceiptText, ShieldCheck } from 'lucide-react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { promotionLevel } from '@/lib/paid-promotion';
import {
    ReportDateButton,
    ReportFilterPanel,
    ReportFilterChip,
    ReportSelect,
    ReportAccountSearch,
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

type Movement = {
    id: string;
    kind: string;
    sourceAccountId: string;
    relation: string;
    sourceRank: number | null;
    sourceAmount: string | null;
    amount: string;
    occurredAt: string;
    postedAt: string | null;
    firstFunding: boolean;
    purchaseKind: string | null;
};
type Member = {
    id: string;
    accountId: string;
    rank: number;
    endsAt: string | null;
    joinedAt: string;
    depositAmount: string;
    totals: IncomeTotals;
};
type Report = ReportPeriod & {
    filters: ReportFilters;
    page: number;
    hasMore: boolean;
    totals?: IncomeTotals;
    counts?: { invited: number; funded: number; orders: number };
    total?: number;
    items: (Movement | Member)[];
};
const activityLabels: Record<string, string> = {
    invitation: 'Invitation registration',
    activation: 'Deposit payment',
    annual: 'Annual fee payment',
};
const purchaseLabels: Record<string, string> = {
    purchase: 'First purchase',
    upgrade: 'Level upgrade',
    renewal: 'Membership renewal',
};

export default function PromotionReport({
    report: p,
    section,
}: {
    report: Report;
    section: 'daily' | 'direct';
}) {
    useClientTranslation();
    const daily = section === 'daily';
    const title = daily ? 'Daily data' : 'Direct invitees';
    const url = `/promotion/${section}`;
    const filters = {
        ...p.filters,
        ...(daily ? { date_from: p.dateFrom, date_to: p.dateTo } : {}),
    };
    const filter = (values: ReportFilters) => visitReport(url, filters, { ...values, page: 1 });
    const { rank = 'all', funding = 'all', activity = 'all' } = p.filters;
    const [draft, setDraft] = useState({ rank, funding, activity });
    useEffect(() => setDraft({ rank, funding, activity }), [rank, funding, activity]);
    const active = Object.entries(daily ? { activity } : { rank, funding }).filter(
        ([, v]) => v && v !== 'all',
    );
    const chips: Record<string, string> = {
        activity: t(activityLabels[String(activity)] ?? 'All activity'),
        rank: reportRank(Number(rank)),
        funding: t(funding === 'funded' ? 'Deposit funded' : 'Deposit not funded'),
    };
    const panel = (
        <ReportFilterPanel
            count={active.length}
            onApply={() =>
                filter(
                    daily
                        ? { activity: draft.activity }
                        : { rank: draft.rank, funding: draft.funding },
                )
            }
            onReset={() => {
                setDraft({ rank: 'all', funding: 'all', activity: 'all' });
                filter(
                    daily ? { activity: 'all' } : { rank: 'all', funding: 'all', account_id: '' },
                );
            }}
        >
            {daily ? (
                <ReportSelect
                    label={t('Team activity')}
                    value={String(draft.activity)}
                    options={[
                        ['all', t('All activity')],
                        ...Object.entries(activityLabels).map(([key, label]): [string, string] => [
                            key,
                            t(label),
                        ]),
                    ]}
                    onChange={(activity) => setDraft({ ...draft, activity })}
                />
            ) : (
                <>
                    <ReportSelect
                        label={t('Current promotion level')}
                        value={String(draft.rank)}
                        options={rankOptions(p.ranks)}
                        onChange={(rank) => setDraft({ ...draft, rank })}
                    />
                    <ReportSelect
                        label={t('Deposit status')}
                        value={String(draft.funding)}
                        options={[
                            ['all', t('All')],
                            ['funded', t('Deposit funded')],
                            ['unfunded', t('Deposit not funded')],
                        ]}
                        onChange={(funding) => setDraft({ ...draft, funding })}
                    />
                </>
            )}
        </ReportFilterPanel>
    );
    return (
        <UserLayout>
            <Head title={t(title)} />
            <div className="promotion-page promotion-report-page">
                <UserPageHeader title={t(title)} backHref="/promotion/invitations" />
                {daily ? (
                    <>
                        <div className="report-toolbar">
                            <ReportDateButton
                                period={p}
                                onChange={(from, to) => filter({ date_from: from, date_to: to })}
                            />
                            {panel}
                        </div>
                        <p className="report-period">
                            {t('Reporting period')}: {p.dateFrom} — {p.dateTo}
                        </p>
                        {p.totals && p.counts && (
                            <>
                                <ReportSummary
                                    totals={p.totals}
                                    title={t('Period commission income')}
                                />
                                <dl className="report-team-counts">
                                    {(
                                        [
                                            ['invited', 'New invitations'],
                                            ['funded', 'Deposit payment count'],
                                            ['orders', 'Annual fee orders'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <div key={key}>
                                            <dd>{p.counts![key]}</dd>
                                            <dt>{t(label)}</dt>
                                        </div>
                                    ))}
                                </dl>
                            </>
                        )}
                        <h2 className="report-section-title">{t('Team activity')}</h2>
                    </>
                ) : (
                    <>
                        <ReportAccountSearch
                            value={String(p.filters.account_id ?? '')}
                            onSearch={(account_id) => filter({ account_id })}
                        />
                        <div className="report-toolbar">
                            <p className="report-caption">
                                {t('{{count}} direct members', { count: p.total ?? 0 })}
                            </p>
                            {panel}
                        </div>
                    </>
                )}
                {(active.length > 0 || (!daily && p.filters.account_id)) && (
                    <div className="report-chips">
                        {!daily && p.filters.account_id && (
                            <ReportFilterChip
                                label={String(p.filters.account_id)}
                                onClear={() => filter({ account_id: '' })}
                            />
                        )}
                        {active.map(([key]) => (
                            <ReportFilterChip
                                key={key}
                                label={chips[key]!}
                                onClear={() => filter({ [key]: 'all' })}
                            />
                        ))}
                    </div>
                )}
                {!p.items.length && (
                    <div className="report-empty" role="status">
                        <ReceiptText size={26} strokeWidth={1.4} aria-hidden="true" />
                        <p>{t('No matching records.')}</p>
                    </div>
                )}
                <div className="report-entries">
                    {daily
                        ? (p.items as Movement[]).map((row) => (
                              <details className="report-entry" key={row.id}>
                                  <summary className="report-entry-summary">
                                      <span className="report-entry-icon" aria-hidden="true">
                                          {row.kind === 'invitation' ? (
                                              <UserPlus size={18} />
                                          ) : row.kind === 'annual' ? (
                                              <ReceiptText size={18} />
                                          ) : (
                                              <ShieldCheck size={18} />
                                          )}
                                      </span>
                                      <div className="report-entry-body">
                                          <div className="report-entry-top">
                                              <h2>
                                                  {t(activityLabels[row.kind] ?? 'Team activity')}
                                              </h2>
                                              {row.kind !== 'invitation' && (
                                                  <strong className="report-positive">
                                                      {reportMoney(row.amount)}
                                                  </strong>
                                              )}
                                          </div>
                                          <div className="report-entry-meta">
                                              <span className="report-account">
                                                  {row.sourceAccountId}
                                              </span>
                                              <span>
                                                  {row.kind === 'invitation'
                                                      ? relationLabel(row.relation)
                                                      : reportRank(row.sourceRank)}
                                              </span>
                                          </div>
                                          <div className="report-entry-meta">
                                              <time>{dateTime(row.occurredAt)}</time>
                                              {row.kind !== 'invitation' && (
                                                  <span>{t('My commission')}</span>
                                              )}
                                              <ChevronDown
                                                  className="report-chevron"
                                                  size={14}
                                                  aria-hidden="true"
                                              />
                                          </div>
                                          {(row.firstFunding || row.purchaseKind) && (
                                              <div className="report-entry-tags">
                                                  {row.firstFunding && (
                                                      <span>{t('First activation')}</span>
                                                  )}
                                                  {row.purchaseKind && (
                                                      <span>
                                                          {t(
                                                              purchaseLabels[row.purchaseKind] ??
                                                                  'Annual fee payment',
                                                          )}
                                                      </span>
                                                  )}
                                              </div>
                                          )}
                                      </div>
                                      <span className="sr-only">{t('View details')}</span>
                                  </summary>
                                  <dl className="report-entry-detail">
                                      <div>
                                          <dt>{t('Referral relationship')}</dt>
                                          <dd>{relationLabel(row.relation)}</dd>
                                      </div>
                                      {row.sourceAmount !== null && (
                                          <>
                                              <div>
                                                  <dt>
                                                      {t(
                                                          row.kind === 'annual'
                                                              ? 'Annual fee paid'
                                                              : 'Deposit amount',
                                                      )}
                                                  </dt>
                                                  <dd>{fullMoney(row.sourceAmount)}</dd>
                                              </div>
                                              <div>
                                                  <dt>{t('My commission')}</dt>
                                                  <dd>{fullMoney(row.amount)}</dd>
                                              </div>
                                          </>
                                      )}
                                      <div>
                                          <dt>{t('Business event time')}</dt>
                                          <dd>{dateTime(row.occurredAt)}</dd>
                                      </div>
                                      {row.postedAt && (
                                          <div>
                                              <dt>{t('Credited at')}</dt>
                                              <dd>{dateTime(row.postedAt)}</dd>
                                          </div>
                                      )}
                                  </dl>
                              </details>
                          ))
                        : (p.items as Member[]).map((row) => (
                              <article className="report-member" key={row.id}>
                                  <div className="report-member-top">
                                      <h2 className="report-account">{row.accountId}</h2>
                                      <span className="report-rank">
                                          {promotionLevel(row.rank)}
                                      </span>
                                  </div>
                                  <div className="report-member-meta">
                                      <span>
                                          {t('Security deposit')}{' '}
                                          <strong>
                                              {/^0(?:\.0+)?$/.test(row.depositAmount)
                                                  ? t('Deposit not funded')
                                                  : reportMoney(row.depositAmount)}
                                          </strong>
                                      </span>
                                  </div>
                                  {row.endsAt && (
                                      <p className="report-caption">
                                          {t('Valid until {{time}}', {
                                              time: dateTime(row.endsAt),
                                          })}
                                      </p>
                                  )}
                                  <Link
                                      className="report-member-income"
                                      href={`/promotion/commissions?account_id=${encodeURIComponent(row.accountId)}`}
                                  >
                                      <span>{t('Commission from this member')}</span>
                                      <strong>
                                          {reportMoney(row.totals.total)}
                                          <ChevronRight size={14} aria-hidden="true" />
                                      </strong>
                                  </Link>
                                  <details className="report-member-breakdown">
                                      <summary>
                                          <span>{t('Income breakdown')}</span>
                                          <ChevronDown size={13} aria-hidden="true" />
                                      </summary>
                                      <dl className="report-entry-detail">
                                          <div>
                                              <dt>{t('Total')}</dt>
                                              <dd>{fullMoney(row.totals.total)}</dd>
                                          </div>
                                          {Object.entries(incomeLabels).map(([key, label]) => (
                                              <div key={key}>
                                                  <dt>{t(label)}</dt>
                                                  <dd>
                                                      {fullMoney(
                                                          row.totals[key as keyof IncomeTotals],
                                                      )}
                                                  </dd>
                                              </div>
                                          ))}
                                          <div>
                                              <dt>{t('Security deposit')}</dt>
                                              <dd>{fullMoney(row.depositAmount)}</dd>
                                          </div>
                                      </dl>
                                  </details>
                                  <p className="report-caption">
                                      {t('Joined at')}: {dateTime(row.joinedAt)}
                                  </p>
                              </article>
                          ))}
                </div>
                <ReportPagination
                    page={p.page}
                    hasMore={p.hasMore}
                    onPage={(page) => visitReport(url, filters, { page })}
                />
                <details className="report-notes">
                    <summary>{t('Statistics notes')}</summary>
                    <p>
                        {t(
                            daily
                                ? 'Income uses posting time; team activity uses event time. Activity filters do not change period totals.'
                                : 'Member levels are currently effective. Income is the commission you earned from this member, including historical rewards.',
                        )}
                    </p>
                    <p>{t('Dates and times follow the company timezone.')}</p>
                </details>
            </div>
        </UserLayout>
    );
}
