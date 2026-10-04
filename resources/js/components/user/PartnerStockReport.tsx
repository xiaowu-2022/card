import { t, dateTime } from '@/i18n';
import { fullMoney } from '@/lib/promotion-report';

export type StockPage<T> = { items: T[]; page: number; total: number; hasMore: boolean };
export type JournalRow = {
    id: string;
    partner_id: string;
    kind: string;
    amount: string;
    business_date: string;
    note: string;
    reverses_id: string | null;
    reversed: boolean;
    actor_id: string;
    created_at: string;
    account_id: string;
};
type Risk = {
    id: string;
    account_id: string;
    rank: number;
    target: number;
    weighted: string;
    remaining: string;
    pending: string;
    ends_at: string;
};
export type StockReport = {
    version: 'partner' | 'standard';
    cashFlow: null | {
        rateObservedAt: string | null;
        assets: {
            asset: string;
            inflow: string;
            outflow: string;
            rate: string | null;
            inflowUsdt: string | null;
            outflowUsdt: string | null;
        }[];
    };
    accountId: string;
    partnerId: string;
    updatedAt: string;
    timezone: string;
    sharePercent: string;
    stock: string | null;
    share: string | null;
    negative: boolean;
    missingRates: number;
    accountBalance: null | {
        advances: string;
        activationCommission: string;
        annualCommission: string;
        manualCommission: string;
        reimbursements: string;
        theoretical: string;
        actual: string;
        difference: string;
    };
    totals: Record<string, string | null>;
    trends: Record<string, Record<string, string>>;
    risks: {
        activeCount: number;
        remaining: string;
        expiredCount: number;
        expiredAmount: string;
        active: StockPage<Risk>;
        expired: StockPage<Risk>;
    };
    journal: StockPage<JournalRow>;
    unvalued: StockPage<{
        id: string;
        asset_code: string;
        fee_amount: string;
        valuation_id: string | null;
    }>;
};
const lines: [string, string, string][] = [
    ['annual', 'Total annual fees paid', '+'],
    ['deposits', 'Ordinary member deposit balances', '+'],
    ['fees', 'Withdrawal fee income', '+'],
    ['activation', 'Activation commissions paid', '−'],
    ['annualCommission', 'Annual fee commissions paid', '−'],
    ['rebates', 'Annual fees returned', '−'],
    ['reimbursements', 'Reimbursed expenses', '−'],
];
const trendNames: Record<string, string> = {
    activation: 'Activation commissions paid',
    deposits: 'First deposit activations',
    annual: 'Annual fee income',
};
export function PartnerStockReport({
    report: r,
    onPage,
    onReverse,
    showShare = true,
}: {
    report: StockReport;
    onPage: (page: number) => void;
    onReverse?: (row: JournalRow) => void;
    showShare?: boolean;
}) {
    const unavailable = t('Incomplete valuation');
    const value = (v: string | null | undefined) => (v == null ? unavailable : fullMoney(v));
    const displayLines: [string, string, string][] =
        r.version === 'partner'
            ? [
                  ['inflow', 'Team total deposits', '+'],
                  ['outflow', 'Team total withdrawals', '−'],
              ]
            : lines;
    const page = r.journal.page;
    const more = [r.journal, r.unvalued, r.risks.active, r.risks.expired].some((p) => p.hasMore);
    return (
        <div className="partner-stock">
            <p className="stock-muted">
                {t('Updated')}: {dateTime(r.updatedAt)} · {r.timezone} · USDT
            </p>
            <h2>{t(r.version === 'partner' ? 'Partner version' : 'Standard version')}</h2>
            <section className="stock-hero">
                <h2>{t('Current total stock')}</h2>
                <strong>{value(r.stock)}</strong>
                {showShare && r.version === 'partner' && (
                    <>
                        <div className="stock-split">
                            <span>
                                {t('Partner share')}:{' '}
                                {r.sharePercent.includes('.')
                                    ? r.sharePercent.replace(/\.?0+$/, '')
                                    : r.sharePercent}
                                %
                            </span>
                            <span>
                                {t('Reference share')}: {value(r.share)}
                            </span>
                        </div>
                        <p>
                            {t(
                                'Reference only. No settlement or transfer is created. Advances do not reduce stock or the reference share.',
                            )}
                        </p>
                    </>
                )}
            </section>
            {r.missingRates > 0 && (
                <p className="stock-warning" role="status">
                    {t('Rates pending')}: {r.missingRates}.{' '}
                    {t(
                        r.version === 'partner'
                            ? 'Current exchange rates are unavailable. USDT valuation is incomplete.'
                            : 'Stock and reference share cannot be fully calculated until fee rates are completed.',
                    )}
                </p>
            )}
            {r.negative && (
                <p className="stock-warning" role="status">
                    {t('Total stock is negative.')}
                </p>
            )}
            <section className="stock-panel">
                <h2>{t('Stock composition')}</h2>
                <dl>
                    {displayLines.map(([key, label, sign]) => (
                        <div key={key}>
                            <dt>
                                {sign} {t(label)}
                            </dt>
                            <dd>
                                {key === 'fees' && r.missingRates
                                    ? unavailable
                                    : value(r.totals[key])}
                            </dd>
                        </div>
                    ))}
                </dl>
                {r.version === 'partner' && (
                    <p className="stock-muted">
                        {t('Partner account commissions are excluded from stock.')}
                    </p>
                )}
            </section>
            {r.cashFlow && (
                <section className="stock-panel">
                    <p className="stock-muted">
                        {t(
                            'Partner stock = descendant deposits − descendant gross withdrawals. Your own deposits and withdrawals are excluded. Commissions, annual fees, deposits held and internal transfers are not stock components.',
                        )}
                    </p>
                    <p className="stock-muted">
                        {t(
                            'All currencies use the same current USDT rates. The valuation changes with market prices.',
                        )}
                    </p>
                    {r.cashFlow.rateObservedAt && (
                        <p>
                            {t('Exchange rate time')}: {dateTime(r.cashFlow.rateObservedAt)}
                        </p>
                    )}
                    {r.cashFlow.assets.map((asset) => (
                        <div className="stock-detail" key={asset.asset}>
                            <strong>{asset.asset}</strong>
                            <span>
                                {t('Team total deposits')}: {asset.inflow} {asset.asset}
                            </span>
                            <span>
                                {t('Team total withdrawals')}: {asset.outflow} {asset.asset}
                            </span>
                            <span>{`1 ${asset.asset} = ${asset.rate ?? '—'} USDT`}</span>
                        </div>
                    ))}
                </section>
            )}
            {r.accountBalance && (
                <section className="stock-panel">
                    <h2>{t('Account balance reconciliation')}</h2>
                    <dl>
                        {(
                            [
                                ['theoretical', 'Theoretical account balance'],
                                ['actual', 'Actual account balance'],
                                ['difference', 'Account balance difference'],
                            ] as const
                        ).map(([key, label]) => (
                            <div key={key}>
                                <dt>{t(label)}</dt>
                                <dd>{value(r.accountBalance?.[key])}</dd>
                            </div>
                        ))}
                    </dl>
                    <p className="stock-muted">
                        {t(
                            'Theoretical balance = personal net advances + activation commissions received + annual fee commissions received + net manual commissions − personal net reimbursements. Actual balance is your available USDT wallet balance. Difference = theoretical − actual.',
                        )}
                    </p>
                </section>
            )}
            <section className="stock-panel">
                <h2>{t('Team alerts')}</h2>
                <dl>
                    <div>
                        <dt>{t('Cumulative advances')}</dt>
                        <dd>{value(r.totals.advances)}</dd>
                    </div>
                    <div>
                        <dt>{t('Active agents at 70% return progress')}</dt>
                        <dd>{r.risks.activeCount}</dd>
                    </div>
                    <div>
                        <dt>{t('Remaining annual fees to return')}</dt>
                        <dd>{value(r.risks.remaining)}</dd>
                    </div>
                    <div>
                        <dt>{t('Expired cycles with pending returns')}</dt>
                        <dd>
                            {r.risks.expiredCount} · {value(r.risks.expiredAmount)}
                        </dd>
                    </div>
                </dl>
                {[r.risks.active, r.risks.expired].map((group, i) => (
                    <details key={i}>
                        <summary>
                            {t(i ? 'Pending expired return details' : '70% progress details')} (
                            {group.total})
                        </summary>
                        {group.items.map((row) => (
                            <div className="stock-detail" key={row.id}>
                                <strong>{row.account_id}</strong>
                                <span>
                                    {t('Progress')}: {row.weighted} / {row.target}
                                </span>
                                <span>
                                    {t('Remaining annual fees to return')}: {value(row.remaining)}
                                </span>
                                {i === 1 && (
                                    <span>
                                        {t('Pending')}: {value(row.pending)}
                                    </span>
                                )}
                            </div>
                        ))}
                    </details>
                ))}
            </section>
            {Object.keys(r.trends).length > 0 && (
                <section className="stock-panel">
                    <h2>{t('Daily trends')}</h2>
                    <p className="stock-muted">
                        {t(
                            'Averages cover complete calendar days before today, including zero-activity days, in the company timezone.',
                        )}
                    </p>
                    {Object.entries(r.trends).map(([key, trend]) => (
                        <div className="stock-trend" key={key}>
                            <h3>{t(trendNames[key] ?? key)}</h3>
                            <dl>
                                {['today', '3', '7', '15', '30'].map((n) => (
                                    <div key={n}>
                                        <dt>
                                            {n === 'today'
                                                ? t('Today')
                                                : t('Previous {{days}} days average', { days: n })}
                                        </dt>
                                        <dd>{value(trend[n])}</dd>
                                    </div>
                                ))}
                            </dl>
                        </div>
                    ))}
                </section>
            )}
            {(r.version === 'partner' || onReverse) && (
                <section className="stock-panel">
                    <h2>
                        {t('Cooperation journal')} ({r.journal.total})
                    </h2>
                    <p className="stock-muted">
                        {t(
                            'Offline cooperation records only; these entries do not represent system payments.',
                        )}
                    </p>
                    {r.journal.items.map((row) => (
                        <div className="stock-detail" key={row.id}>
                            <strong>
                                {row.account_id} ·{' '}
                                {t(row.kind === 'ADVANCE' ? 'Advance' : 'Reimbursement')}{' '}
                                {row.reverses_id && `· ${t('Reversal')}`}
                            </strong>
                            <span>
                                {row.reverses_id ? '−' : ''}
                                {value(row.amount)} · {row.business_date}
                            </span>
                            <p>{row.note}</p>
                            {onReverse && (
                                <small>
                                    {t('Recorded by')}: {row.actor_id} · {dateTime(row.created_at)}
                                    {row.reverses_id &&
                                        ` · ${t('Reverses entry')}: ${row.reverses_id}`}
                                </small>
                            )}
                            {row.reversed && <small>{t('Reversed')}</small>}
                            {onReverse && !row.reverses_id && !row.reversed && (
                                <button
                                    type="button"
                                    className="stock-link"
                                    onClick={() => onReverse(row)}
                                >
                                    {t('Record reversal')}
                                </button>
                            )}
                        </div>
                    ))}
                </section>
            )}
            {r.missingRates > 0 && r.version !== 'partner' && (
                <section className="stock-panel">
                    <h2>
                        {t('Rates pending')} ({r.unvalued.total})
                    </h2>
                    {r.unvalued.items.map((row) => (
                        <div className="stock-detail" key={row.id}>
                            <span>
                                {row.fee_amount} {row.asset_code}
                            </span>
                            <small>{row.id}</small>
                        </div>
                    ))}
                </section>
            )}
            {(page > 1 || more) && (
                <nav className="stock-pagination">
                    <button type="button" disabled={page <= 1} onClick={() => onPage(page - 1)}>
                        {t('Previous')}
                    </button>
                    <span>{page}</span>
                    <button type="button" disabled={!more} onClick={() => onPage(page + 1)}>
                        {t('Next')}
                    </button>
                </nav>
            )}
            <p className="stock-muted">
                {t(
                    r.version === 'partner'
                        ? 'Includes all descendants, including partner branches, but excludes your own external deposits and withdrawals. Teams overlap; do not add reports together. Historical totals use current team relationships.'
                        : 'Includes this account and all descendants. Historical totals use current team relationships.',
                )}
            </p>
        </div>
    );
}
