import { Head, router } from '@inertiajs/react';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, useAdminTranslation, dateTime } from '@/i18n/admin';
import { displayMoney } from '@/lib/exact-amount';

const types: Record<string, string> = {
    PROMOTION_ANNUAL_FEE: 'Annual fee income',
    PROMOTION_FEE_REBATE: 'Annual fee rebates',
    PROMOTION_ANNUAL_COMMISSION: 'Annual fee commission cost',
    ASSET_DEPOSIT: 'Company book: customer top-up',
    ASSET_WITHDRAWAL_SETTLE: 'Company book: customer withdrawal',
    ASSET_EXCHANGE_IN: 'Internal exchange',
    WALLET_TOPUP_CREDIT: 'Company book: customer top-up',
    WITHDRAWAL_SETTLE: 'Company book: customer withdrawal',
    WITHDRAWAL_FEE_INCOME: 'Withdrawal fee income',
    COMMISSION_EARN: 'Company book: commission cost',
    COMMISSION_TRANSFER: 'Company book: commission transfer',
    SECURITY_DEPOSIT_FUND: 'Company book: security deposit funded',
    SECURITY_DEPOSIT_REFUND: 'Company book: security deposit refunded',
    CARD_ISSUE_FEE_SETTLE: 'Company book: opening fee income',
    CARD_INITIAL_LOAD_SETTLE: 'Company book: card funding',
    CARD_LOAD_SETTLE: 'Company book: card funding',
    CARD_RETURN_SETTLE: 'Company book: card balance returned',
    CARD_CANCEL_RETURN_SETTLE: 'Company book: card balance returned',
};
type Book = {
    date: string;
    timezone: string;
    totals: {
        topups: string;
        withdrawals: string;
        commissionCost: string;
        feeIncome: string;
        activationCommissions: string;
        annualFees: string;
        annualRebates: string;
        annualCommissions: string;
    };
    lifetimeTotals: {
        activationCommissions: string;
        annualFees: string;
        annualRebates: string;
        annualCommissions: string;
        topups: string;
        withdrawals: string;
        commissionCost: string;
        feeIncome: string;
    };
    rows: { id: string; type: string; amount: string; occurredAt: string }[];
    page: number;
    hasMore: boolean;
};
export default function CompanyFunds({ book: b }: { book: Book }) {
    useAdminTranslation();
    const visit = (values: Record<string, string | number>) =>
        router.get('/admin/company-funds', { date: b.date, ...values });
    return (
        <TenantAdminLayout>
            <Head title={t('Company fund book')} />
            <div className="mx-auto max-w-5xl space-y-6">
                <h1 className="text-2xl font-semibold">{t('Company fund book')}</h1>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'All amounts come from completed accounting events. Customer deposits are not company revenue; commission is counted once as a company cost. This book has no editable balance.',
                    )}
                </p>
                <h2 className="font-semibold">{t('All-time company totals')}</h2>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {(
                        [
                            ['Customer top-ups', b.lifetimeTotals.topups],
                            ['Customer withdrawals', b.lifetimeTotals.withdrawals],
                            ['Commission cost', b.lifetimeTotals.commissionCost],
                            ['Fee income', b.lifetimeTotals.feeIncome],
                            ['Activation commission cost', b.lifetimeTotals.activationCommissions],
                            ['Annual fee income', b.lifetimeTotals.annualFees],
                            ['Annual fee rebates', b.lifetimeTotals.annualRebates],
                            ['Annual fee commission cost', b.lifetimeTotals.annualCommissions],
                        ] as const
                    ).map(([label, amount]) => (
                        <section className="rounded-xl border bg-surface p-5" key={label}>
                            <h3 className="text-sm text-muted-foreground">{t(label)}</h3>
                            <p className="mt-2 break-all text-lg font-semibold tabular-nums">
                                {displayMoney(amount)} {t('U')}
                            </p>
                        </section>
                    ))}
                </div>
                <h2 className="font-semibold">{t('Daily company movements')}</h2>
                <label className="block max-w-xs space-y-2 text-sm">
                    <span>
                        {t('Date')} · {b.timezone}
                    </span>
                    <Input
                        type="date"
                        value={b.date}
                        onChange={(e) => e.target.value && visit({ date: e.target.value, page: 1 })}
                    />
                </label>
                <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                    {(
                        [
                            ['Customer top-ups', b.totals.topups],
                            ['Customer withdrawals', b.totals.withdrawals],
                            ['Commission cost', b.totals.commissionCost],
                            ['Fee income', b.totals.feeIncome],
                            ['Activation commission cost', b.totals.activationCommissions],
                            ['Annual fee income', b.totals.annualFees],
                            ['Annual fee rebates', b.totals.annualRebates],
                            ['Annual fee commission cost', b.totals.annualCommissions],
                        ] as const
                    ).map(([label, amount]) => (
                        <section className="rounded-xl border bg-surface p-5" key={label}>
                            <h2 className="text-sm text-muted-foreground">{t(label)}</h2>
                            <p className="mt-2 break-all text-lg font-semibold tabular-nums">
                                {displayMoney(amount)} {t('U')}
                            </p>
                        </section>
                    ))}
                </div>
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <table className="w-full min-w-[36rem] text-left text-sm">
                        <thead className="border-b bg-muted">
                            <tr>
                                <th className="p-4">{t('Date')}</th>
                                <th className="p-4">{t('Fund book entry')}</th>
                                <th className="p-4 text-right">{t('Amount')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {b.rows.map((row) => (
                                <tr className="border-b last:border-0" key={row.id}>
                                    <td className="p-4">{dateTime(row.occurredAt)}</td>
                                    <td className="p-4">
                                        {t(types[row.type] ?? 'Fund book entry')}
                                    </td>
                                    <td className="p-4 text-right tabular-nums">
                                        {displayMoney(row.amount)} {t('U')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                {b.rows.length === 0 && (
                    <p className="text-sm text-muted-foreground">
                        {t('No fund book entries on this date.')}
                    </p>
                )}
                <div className="flex justify-between">
                    <Button
                        variant="secondary"
                        disabled={b.page === 1}
                        onClick={() => visit({ page: b.page - 1 })}
                    >
                        {t('Previous')}
                    </Button>
                    <Button
                        variant="secondary"
                        disabled={!b.hasMore}
                        onClick={() => visit({ page: b.page + 1 })}
                    >
                        {t('Next')}
                    </Button>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
