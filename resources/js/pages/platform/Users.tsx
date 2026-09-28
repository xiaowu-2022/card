import { Head, Link } from '@inertiajs/react';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';

type User = {
    id: string;
    companyName: string;
    companyId: string;
    promotionRank: number;
    accountId: string;
    displayName: string | null;
    email: string | null;
    status: string;
    createdAt: string;
    lastLoginAt: string | null;
    availableBalance?: string;
    securityDeposit?: string;
    commission?: string;
    totalWithdrawn?: string;
};

export default function Users({
    users,
    companies,
    filters,
    financialAccess,
}: {
    users: AccountPage<User>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; status?: string };
    financialAccess: { balances: boolean; commission: boolean; withdrawals: boolean };
}) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('Users')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('Users')}
                    description={t(
                        'User records and promotion levels. Filter by company, account or status.',
                    )}
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={users}
                    filters={filters}
                    url="/platform/users"
                    searchLabel={t('Search name, account ID or email')}
                    statuses={['ACTIVE', 'SUSPENDED', 'DISABLED']}
                    columns={[
                        { label: 'Tenant', render: (row) => row.companyName },
                        {
                            label: 'Account ID',
                            render: (row) => <span className="font-mono">{row.accountId}</span>,
                        },
                        { label: 'Name', render: (row) => row.displayName ?? '—' },
                        {
                            label: 'Email',
                            className: 'w-56 max-w-56',
                            render: (row) => (
                                <span
                                    className="block w-48 truncate"
                                    title={row.email ?? undefined}
                                >
                                    {row.email ?? '—'}
                                </span>
                            ),
                        },
                        {
                            label: 'Agent level',
                            render: (row) => (
                                <Link
                                    className="text-primary underline underline-offset-4"
                                    href={`/platform/tenants/${row.companyId}/users/${row.id}/promotion`}
                                >
                                    {row.promotionRank
                                        ? t('Mastercard level {{rank}}', {
                                              rank: row.promotionRank,
                                          })
                                        : t('Ordinary member')}
                                </Link>
                            ),
                        },
                        ...(financialAccess.balances
                            ? [
                                  {
                                      label: 'Balance',
                                      render: (row: User) => (
                                          <MoneyDisplay
                                              amount={row.availableBalance!}
                                              asset="USDT"
                                          />
                                      ),
                                  },
                                  {
                                      label: 'Security deposit',
                                      render: (row: User) => (
                                          <MoneyDisplay
                                              amount={row.securityDeposit!}
                                              asset="USDT"
                                          />
                                      ),
                                  },
                              ]
                            : []),
                        ...(financialAccess.commission
                            ? [
                                  {
                                      label: 'Cumulative commission',
                                      render: (row: User) => (
                                          <MoneyDisplay amount={row.commission!} asset="USDT" />
                                      ),
                                  },
                              ]
                            : []),
                        ...(financialAccess.withdrawals
                            ? [
                                  {
                                      label: 'Total withdrawn',
                                      render: (row: User) => (
                                          <span
                                              title={t(
                                                  'Successful withdrawal amounts including fees.',
                                              )}
                                          >
                                              <MoneyDisplay
                                                  amount={row.totalWithdrawn!}
                                                  asset="USDT"
                                              />
                                          </span>
                                      ),
                                  },
                              ]
                            : []),
                        {
                            label: 'Status',
                            render: (row) => (
                                <StatusBadge
                                    status={
                                        row.status === 'ACTIVE'
                                            ? 'SUCCESS'
                                            : row.status === 'SUSPENDED'
                                              ? 'WARNING'
                                              : 'DANGER'
                                    }
                                    label={t(row.status)}
                                />
                            ),
                        },
                        { label: 'Created', render: (row) => dateTime(row.createdAt) },
                        {
                            label: 'Last login',
                            render: (row) => (row.lastLoginAt ? dateTime(row.lastLoginAt) : '—'),
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
