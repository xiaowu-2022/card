import { CustomerRemarkDialog } from '@/components/admin/CustomerRemarkDialog';
import { openAdminEditor } from '@/components/admin/editor-navigation';
import { CreateUserDialog } from '@/components/admin/CreateUserDialog';
import { supportRequest } from '@/components/support/supportRequest';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { UserKycDrawer } from '@/components/admin/UserKycDrawer';
import { UserFundsDrawer } from '@/components/admin/UserFundsDrawer';
import { initialFundsTarget, fundsLocation } from '@/components/admin/user-funds-state';
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
    remark: string | null;
    remarkRevision: number;
    supportAgent: boolean;
    supportRevision: number;
    id: string;
    companyName: string;
    companyId: string;
    promotionRank: number;
    ordinaryMember: boolean;
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
    canChangeInvitation,
    canChangeReferrer,
    canAdjustCommission,
    canViewKyc,
    canViewFunds,
    canViewTopups,
    canManageSupport,
    canCreateUser,
    canRemark,
}: {
    canRemark: boolean;
    canCreateUser: boolean;
    canManageSupport: boolean;
    canAdjustWallet: boolean;
    canChangeInvitation: boolean;
    canChangeReferrer: boolean;
    canAdjustCommission: boolean;
    canViewKyc: boolean;
    canViewFunds: boolean;
    canViewTopups: boolean;
    users: AccountPage<User>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; status?: string; support?: string };
    financialAccess: { balances: boolean; commission: boolean; withdrawals: boolean };
}) {
    useAdminTranslation();
    const [remarkUser, setRemarkUser] = useState<User | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [supportBusy, setSupportBusy] = useState(false);
    const [supportError, setSupportError] = useState('');
    const { url } = usePage();
    const [kycTarget, setKycTarget] = useState(initialKycTarget);
    const [fundsTarget, setFundsTarget] = useState(initialFundsTarget);
    const kycTrigger = useRef<HTMLElement | null>(null);
    const kycOpening = useRef(false);
    useEffect(() => {
        const sync = () => {
            setKycTarget(initialKycTarget());
            setFundsTarget(initialFundsTarget());
        };
        sync();
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, [url]);
    return (
        <PlatformLayout
            title={t('Users')}
            actions={
                <>
                    {canCreateUser && (
                        <Button onClick={() => setCreateOpen(true)}>{t('Add account')}</Button>
                    )}
                    {canViewTopups && (
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
                    )}
                </>
            }
        >
            <Head title={t('Users')} />
            {canCreateUser && createOpen && (
                <CreateUserDialog
                    companies={companies}
                    company={filters.company}
                    onClose={() => setCreateOpen(false)}
                />
            )}
            <div className="space-y-4">
                {supportError && <p role="alert">{supportError}</p>}
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={users}
                    filters={filters}
                    url="/platform/users"
                    searchLabel={t('Search name, account ID or email')}
                    statuses={['ACTIVE', 'SUSPENDED', 'DISABLED']}
                    selectFilters={[
                        {
                            key: 'support',
                            label: 'Support agent',
                            allLabel: 'All users',
                            values: ['Enabled', 'Disabled'],
                        },
                    ]}
                    columns={[
                        {
                            label: 'Company / User',
                            className:
                                'sticky left-0 z-10 w-64 min-w-64 max-w-64 bg-surface shadow-[1px_0_0_var(--color-border)]',
                            render: (row) => (
                                <div className="w-56 space-y-1.5">
                                    <p
                                        className="truncate text-sm font-semibold text-foreground"
                                        title={row.displayName ?? undefined}
                                    >
                                        {row.displayName || '—'}{' '}
                                        {row.supportAgent && (
                                            <span className="ml-2 text-xs text-emerald-700">
                                                {t('Support agent')}
                                            </span>
                                        )}
                                    </p>
                                    <p
                                        className="truncate text-xs text-muted-foreground"
                                        title={row.email ?? undefined}
                                    >
                                        {row.email ?? '—'}
                                    </p>
                                    <div className="flex min-w-0 items-center gap-2 text-xs text-muted-foreground">
                                        <span className="min-w-0 truncate" title={row.companyName}>
                                            {row.companyName}
                                        </span>
                                        <span aria-hidden="true">·</span>
                                        <span className="shrink-0 font-normal tabular-nums">
                                            {t('Account ID')}: {row.accountId}
                                        </span>
                                    </div>
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
                                        : t(
                                              row.ordinaryMember
                                                  ? 'Ordinary member'
                                                  : 'Registered member',
                                          )}
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
                        ...(canRemark ||
                        canManageSupport ||
                        canViewTopups ||
                        canViewFunds ||
                        financialAccess.withdrawals ||
                        canViewKyc ||
                        canAdjustWallet ||
                        canChangeInvitation ||
                        canChangeReferrer ||
                        canAdjustCommission
                            ? [
                                  {
                                      label: 'Actions',
                                      render: (row: User) => (
                                          <div className="flex items-center gap-2">
                                              {(canManageSupport ||
                                                  canViewKyc ||
                                                  canViewFunds ||
                                                  canViewTopups ||
                                                  financialAccess.withdrawals ||
                                                  canAdjustWallet ||
                                                  canChangeInvitation ||
                                                  canChangeReferrer ||
                                                  canAdjustCommission) && (
                                                  <DropdownMenu>
                                                      <DropdownMenuTrigger asChild>
                                                          <Button
                                                              variant="secondary"
                                                              size="sm"
                                                              onFocus={(event) => {
                                                                  kycTrigger.current =
                                                                      event.currentTarget;
                                                              }}
                                                          >
                                                              {t('More actions')}
                                                          </Button>
                                                      </DropdownMenuTrigger>
                                                      <DropdownMenuContent
                                                          className="flex min-w-40 flex-col"
                                                          align="end"
                                                          onCloseAutoFocus={(event) => {
                                                              if (kycOpening.current) {
                                                                  event.preventDefault();
                                                                  kycOpening.current = false;
                                                              }
                                                          }}
                                                      >
                                                          {canRemark && (
                                                              <DropdownMenuItem
                                                                  onSelect={() =>
                                                                      setRemarkUser(row)
                                                                  }
                                                              >
                                                                  {t('Customer remark')}
                                                              </DropdownMenuItem>
                                                          )}
                                                          {canManageSupport && (
                                                              <DropdownMenuItem
                                                                  disabled={
                                                                      supportBusy ||
                                                                      (!row.supportAgent &&
                                                                          row.status !== 'ACTIVE')
                                                                  }
                                                                  onSelect={() => {
                                                                      setSupportBusy(true);
                                                                      setSupportError('');
                                                                      void (async () => {
                                                                          try {
                                                                              await supportRequest(
                                                                                  `/platform/tenants/${row.companyId}/users/${row.id}/support-agent`,
                                                                                  {
                                                                                      enabled:
                                                                                          !row.supportAgent,
                                                                                      revision:
                                                                                          row.supportRevision,
                                                                                  },
                                                                              );
                                                                              router.reload({
                                                                                  only: ['users'],
                                                                                  onFinish: () =>
                                                                                      setSupportBusy(
                                                                                          false,
                                                                                      ),
                                                                              });
                                                                          } catch {
                                                                              setSupportError(
                                                                                  t(
                                                                                      'Unable to save. Refresh and try again.',
                                                                                  ),
                                                                              );
                                                                              setSupportBusy(false);
                                                                          }
                                                                      })();
                                                                  }}
                                                              >
                                                                  {t(
                                                                      row.supportAgent
                                                                          ? 'Remove support access'
                                                                          : 'Make support agent',
                                                                  )}
                                                              </DropdownMenuItem>
                                                          )}
                                                          {canViewFunds && (
                                                              <DropdownMenuItem
                                                                  onSelect={() => {
                                                                      kycOpening.current = true;
                                                                      const target = {
                                                                          company: row.companyId,
                                                                          user: row.id,
                                                                      };
                                                                      kycLocation(null);
                                                                      setKycTarget(null);
                                                                      fundsLocation(target);
                                                                      setFundsTarget(target);
                                                                  }}
                                                              >
                                                                  {t('User fund flows')}
                                                              </DropdownMenuItem>
                                                          )}
                                                          {canViewKyc && (
                                                              <DropdownMenuItem
                                                                  onSelect={() => {
                                                                      kycOpening.current = true;
                                                                      fundsLocation(null);
                                                                      setFundsTarget(null);
                                                                      const target = {
                                                                          company: row.companyId,
                                                                          user: row.id,
                                                                      };
                                                                      kycLocation(target);
                                                                      setKycTarget(target);
                                                                  }}
                                                              >
                                                                  {t('Verification')}
                                                              </DropdownMenuItem>
                                                          )}
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
                                                          {canChangeInvitation && (
                                                              <DropdownMenuItem
                                                                  onSelect={() => {
                                                                      kycOpening.current = true;
                                                                      openAdminEditor(
                                                                          `/platform/tenants/${row.companyId}/users/${row.id}/invitation-code`,
                                                                          kycTrigger.current,
                                                                      );
                                                                  }}
                                                              >
                                                                  {t('Change invitation code')}
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
            {canViewFunds && fundsTarget && !kycTarget && (
                <UserFundsDrawer
                    key={`${fundsTarget.company}:${fundsTarget.user}`}
                    target={fundsTarget}
                    trigger={kycTrigger}
                    onClose={() => {
                        fundsLocation(null);
                        setFundsTarget(null);
                    }}
                />
            )}
            {remarkUser && (
                <CustomerRemarkDialog user={remarkUser} onClose={() => setRemarkUser(null)} />
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
