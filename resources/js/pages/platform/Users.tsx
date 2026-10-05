import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { UserKycDrawer } from '@/components/admin/UserKycDrawer';
import { initialKycTarget, kycLocation } from '@/components/admin/user-kyc-state';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
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
    const { url } = usePage();
    const [kycTarget, setKycTarget] = useState(initialKycTarget);
    const kycTrigger = useRef<HTMLElement | null>(null);
    useEffect(() => {
        const sync = () => setKycTarget(initialKycTarget());
        sync();
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, [url]);
    return (
        <PlatformLayout
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
        >
            <Head title={t('Users')} />
            <div className="space-y-4">
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
                                          <div className="flex items-center gap-2">
                                              {canViewKyc && (
                                                  <Button
                                                      variant="secondary"
                                                      size="sm"
                                                      onClick={(event) => {
                                                          kycTrigger.current = event.currentTarget;
                                                          const target = {
                                                              company: row.companyId,
                                                              user: row.id,
                                                          };
                                                          kycLocation(target);
                                                          setKycTarget(target);
                                                      }}
                                                  >
                                                      {t('Verification')}
                                                  </Button>
                                              )}
                                              {(canViewTopups ||
                                                  financialAccess.withdrawals ||
                                                  canAdjustWallet ||
                                                  canChangeReferrer ||
                                                  canAdjustCommission) && (
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
                                              )}
                                          </div>
                                      ),
                                  },
                              ]
                            : []),
                    ]}
                />
            </div>
            {canViewKyc && kycTarget && (
                <UserKycDrawer
                    key={`${kycTarget.company}:${kycTarget.user}`}
                    target={kycTarget}
                    trigger={kycTrigger}
                    onClose={() => {
                        kycLocation(null);
                        setKycTarget(null);
                    }}
                />
            )}
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
