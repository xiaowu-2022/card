import { Head } from '@inertiajs/react';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';

type User = {
    id: string;
    companyName: string;
    accountId: string;
    displayName: string | null;
    email: string | null;
    phone: string | null;
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
                        'Read-only user records across companies. Filter by company, account or status.',
                    )}
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={users}
                    filters={filters}
                    url="/platform/users"
                    searchLabel={t('Search name, account ID, email or phone')}
                    statuses={['ACTIVE', 'SUSPENDED', 'DISABLED']}
                    columns={[
                        { label: 'Tenant', render: (row) => row.companyName },
                        {
                            label: 'Account ID',
                            render: (row) => <span className="font-mono">{row.accountId}</span>,
                        },
                        { label: 'Name', render: (row) => row.displayName ?? '—' },
                        { label: 'Email', render: (row) => row.email ?? '—' },
                        { label: 'Phone number', render: (row) => row.phone ?? '—' },
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
