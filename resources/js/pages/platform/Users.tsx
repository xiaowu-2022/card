import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { Head, Link } from '@inertiajs/react';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { MoneyDisplay } from '@/components/admin/MoneyDisplay';

type Wallet = {
    id: string;
    asset: string;
    status: string;
    available: string;
    securityDeposit: string;
    held: string;
};
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
    wallets?: Wallet[];
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
    canAdjustWallet,
    canChangeReferrer,
    canAdjustCommission,
    canViewKyc,
    canViewTopups,
}: {
    canAdjustWallet: boolean;
    canChangeReferrer: boolean;
    canAdjustCommission: boolean;
    canViewKyc: boolean;
    canViewTopups: boolean;
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
                        'User records, wallet balances and promotion levels. Each currency is shown separately.',
                    )}
                    actions={
                        canViewTopups && (
                            <Button asChild>
                                <Link
                                    href={
                                        filters.company
                                            ? `/platform/topups?company=${filters.company}`
                                            : '/platform/topups'
                                    }
                                >
                                    {t('Top-up management')}
                                </Link>
                            </Button>
                        )
                    }
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
                        {
                            label: 'Company / User',
                            className:
                                'sticky left-0 z-10 w-64 min-w-64 max-w-64 bg-surface shadow-[1px_0_0_var(--color-border)]',
                            render: (row) => (
                                <div className="w-56 space-y-1.5">
                                    <p
                                        className="truncate text-xs text-muted-foreground"
                                        title={row.companyName}
                                    >
                                        {row.companyName}
                                    </p>
                                    <p className="font-mono font-semibold">{row.accountId}</p>
                                    {row.displayName && (
                                        <p className="truncate text-xs" title={row.displayName}>
                                            {row.displayName}
                                        </p>
                                    )}
                                    <p
                                        className="truncate text-xs text-muted-foreground"
                                        title={row.email ?? undefined}
                                    >
                                        {row.email ?? '—'}
                                    </p>
                                </div>
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
                                      label: 'Wallet status',
                                      render: (row: User) =>
                                          row.wallets?.length ? (
                                              <div className="space-y-2">
                                                  {row.wallets.map((wallet) => (
                                                      <div
                                                          key={wallet.id}
                                                          className="flex min-h-6 items-center gap-2"
                                                      >
                                                          <span className="w-10 text-xs">
                                                              {wallet.asset}
                                                          </span>
                                                          <StatusBadge
                                                              status={
                                                                  wallet.status === 'ACTIVE'
                                                                      ? 'SUCCESS'
                                                                      : 'WARNING'
                                                              }
                                                              label={t(wallet.status)}
                                                          />
                                                      </div>
                                                  ))}
                                              </div>
                                          ) : (
                                              t('No wallet')
                                          ),
                                  },
                                  {
                                      label: 'Available balance',
                                      render: (row: User) => (
                                          <WalletAmounts wallets={row.wallets} field="available" />
                                      ),
                                  },
                                  {
                                      label: 'Held amount',
                                      render: (row: User) => (
                                          <WalletAmounts wallets={row.wallets} field="held" />
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
                        ...(canViewTopups ||
                        financialAccess.withdrawals ||
                        canViewKyc ||
                        canAdjustWallet ||
                        canChangeReferrer ||
                        canAdjustCommission
                            ? [
                                  {
                                      label: 'Actions',
                                      render: (row: User) => (
                                          <DropdownMenu>
                                              <DropdownMenuTrigger asChild>
                                                  <Button variant="secondary" size="sm">
                                                      {t('More actions')}
                                                  </Button>
                                              </DropdownMenuTrigger>
                                              <DropdownMenuContent align="end">
                                                  {canViewTopups && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/topups?company=${row.companyId}&search=${encodeURIComponent(row.accountId)}`}
                                                          >
                                                              {t('Deposit orders')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                                  {financialAccess.withdrawals && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/asset-withdrawals?company=${row.companyId}&search=${encodeURIComponent(row.accountId)}`}
                                                          >
                                                              {t('Withdrawal orders')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                                  {canViewKyc && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/kyc?company=${row.companyId}&search=${row.id}`}
                                                          >
                                                              {t('View identity verification')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                                  {canAdjustWallet && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/tenants/${row.companyId}/users/${row.id}/wallet-adjustments`}
                                                          >
                                                              {t('Wallet adjustment')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                                  {canChangeReferrer && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/tenants/${row.companyId}/users/${row.id}/referrer`}
                                                          >
                                                              {t('Change referrer')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                                  {canAdjustCommission && (
                                                      <DropdownMenuItem asChild>
                                                          <Link
                                                              href={`/platform/tenants/${row.companyId}/users/${row.id}/manual-commissions`}
                                                          >
                                                              {t('Adjust commission')}
                                                          </Link>
                                                      </DropdownMenuItem>
                                                  )}
                                              </DropdownMenuContent>
                                          </DropdownMenu>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}

function WalletAmounts({ wallets, field }: { wallets?: Wallet[]; field: 'available' | 'held' }) {
    return wallets?.length ? (
        <div className="space-y-2">
            {wallets.map((wallet) => (
                <div key={wallet.id} className="flex min-h-6 items-center">
                    <MoneyDisplay amount={wallet[field]} asset={wallet.asset} />
                </div>
            ))}
        </div>
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}
