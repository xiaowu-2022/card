import { Head } from '@inertiajs/react';
import { t, useAdminTranslation, dateTime } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { MoneyDisplay } from '@/components/admin/MoneyDisplay';
import { UserInfoCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { Input } from '@/components/ui/input';

type Flow = {
    id: string;
    companyName: string;
    asset: string;
    event: string;
    kind: string;
    reference: string | null;
    time: string;
    movements: { id: string; account: string; amount: string; user: UserInfo | null }[];
};

export default function Funds({
    flows,
    filters,
    companies,
    assets,
    events,
}: {
    flows: AccountPage<Flow>;
    filters: Record<string, string | undefined>;
    companies: { id: string; name: string }[];
    assets: string[];
    events: { value: string; label: string }[];
}) {
    useAdminTranslation();
    return (
        <PlatformLayout title={t('Fund flows')}>
            <Head title={t('Fund flows')} />
            <PlatformAccountTable
                key={JSON.stringify(filters)}
                page={flows}
                companies={companies}
                filters={filters}
                url="/platform/funds"
                searchLabel={t('Search customer, remark, account or reference')}
                selectFilters={[
                    { key: 'asset', label: 'Currency', allLabel: 'All currencies', values: assets },
                    {
                        key: 'event',
                        label: 'Type',
                        allLabel: 'All types',
                        values: events.map((e) => e.value),
                        valueLabels: Object.fromEntries(
                            events.map((e) => [e.value, `${t(e.label)} · ${t(e.value)}`]),
                        ),
                    },
                ]}
                textFilters={[
                    {
                        key: 'from',
                        label: 'Start date',
                        placeholder: 'YYYY-MM-DD',
                        render: ({ value, onChange }) => (
                            <Input
                                aria-label={t('Start date')}
                                type="date"
                                value={value}
                                onChange={(e) => onChange(e.target.value)}
                            />
                        ),
                    },
                    {
                        key: 'to',
                        label: 'End date',
                        placeholder: 'YYYY-MM-DD',
                        render: ({ value, onChange }) => (
                            <Input
                                aria-label={t('End date')}
                                type="date"
                                value={value}
                                onChange={(e) => onChange(e.target.value)}
                            />
                        ),
                    },
                ]}
                columns={[
                    { label: 'Company', render: (row) => row.companyName },
                    { label: 'Time', render: (row) => dateTime(row.time) },
                    {
                        label: 'Type',
                        render: (row) => <div>{t(row.kind)}</div>,
                    },
                    { label: 'Currency', render: (row) => row.asset },
                    {
                        label: 'Fund movements',
                        className: 'min-w-80',
                        render: (row) => (
                            <div className="divide-y">
                                {row.movements.map((move) => (
                                    <div
                                        key={move.id}
                                        className="flex items-center justify-between gap-4 py-2"
                                    >
                                        <div className="min-w-0">
                                            {move.user && (
                                                <UserInfoCell
                                                    user={move.user}
                                                    interactive={false}
                                                />
                                            )}
                                            <p className="text-xs text-muted-foreground">
                                                {t(move.account)}
                                            </p>
                                        </div>
                                        <span
                                            className={`shrink-0 tabular-nums ${move.amount.startsWith('-') ? 'text-rose-600' : 'text-emerald-700'}`}
                                        >
                                            {!move.amount.startsWith('-') && '+'}
                                            <MoneyDisplay
                                                amount={move.amount}
                                                asset={row.asset}
                                                hideSymbol
                                            />
                                        </span>
                                    </div>
                                ))}
                            </div>
                        ),
                    },
                    {
                        label: 'Reference',
                        className: 'max-w-56 break-all',
                        render: (row) => row.reference || row.id,
                    },
                ]}
            />
        </PlatformLayout>
    );
}
