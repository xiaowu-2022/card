import { PartnerHierarchyPanel } from './PartnerHierarchyPanel';
import { UserInfoCell } from '@/components/admin/UserInfoCell';
import { Fragment, type ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UserOrdersPanel } from './UserOrdersPanel';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { UserRestrictionsDialog } from '@/components/admin/UserRestrictionsDialog';
import { CustomerRemarkDialog } from '@/components/admin/CustomerRemarkDialog';
import { openAdminEditor } from '@/components/admin/editor-navigation';
import { CreateUserDialog } from '@/components/admin/CreateUserDialog';
import { supportRequest } from '@/components/support/supportRequest';
import { Button } from '@/components/ui/button';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useEffect, useId, useRef, useState } from 'react';
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
export type PlatformUser = {
    partnerId?: string | null;
    promotionTeamCount?: number;
    invitationCode?: string | null;
    ancestors?: {
        id: string;
        accountId: string;
        displayName: string | null;
        email: string | null;
        distance: number;
        promotionRank: number;
        ordinaryMember: boolean;
    }[];
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
    phone?: string | null;
    status: string;
    createdAt: string;
    lastLoginAt: string | null;
    wallets?: Wallet[];
    availableBalance?: string;
    securityDeposit?: string;
    commission?: string;
    receiptTotals?: { asset: string; actual: string; advance: string }[];
    totalWithdrawn?: string;
};

export default function UserManagement({
    detail = false,
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
    canManageRestrictions,
}: {
    detail?: boolean;
    canManageRestrictions: boolean;
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
    users: AccountPage<PlatformUser>;
    companies: { id: string; name: string }[];
    filters: {
        company?: string;
        search?: string;
        status?: string;
        support?: string;
        partner?: string;
    };
    financialAccess: {
        receipts: boolean;
        balances: boolean;
        commission: boolean;
        withdrawals: boolean;
    };
}) {
    useAdminTranslation();
    const [detailTab, setDetailTab] = useState('basic');
    const detailTabsId = useId();
    const [restrictionsUser, setRestrictionsUser] = useState<PlatformUser | null>(null);
    const [remarkUser, setRemarkUser] = useState<PlatformUser | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [supportBusy, setSupportBusy] = useState(false);
    const [supportError, setSupportError] = useState('');
    const { url } = usePage();
    const [kycTarget, setKycTarget] = useState(initialKycTarget);
    const [fundsTarget, setFundsTarget] = useState(initialFundsTarget);
    const kycTrigger = useRef<HTMLElement | null>(null);
    useEffect(() => {
        const sync = () => {
            setKycTarget(initialKycTarget());
            setFundsTarget(initialFundsTarget());
        };
        sync();
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, [url]);
    const Layout = detail ? DetailLayout : PlatformLayout;
    return (
        <Layout
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
            {!detail && <Head title={t('Users')} />}
            {restrictionsUser && (
                <UserRestrictionsDialog
                    key={restrictionsUser.id}
                    user={restrictionsUser}
                    onClose={() => setRestrictionsUser(null)}
                />
            )}
            {canCreateUser && createOpen && (
                <CreateUserDialog
                    companies={companies}
                    company={filters.company}
                    onClose={() => setCreateOpen(false)}
                />
            )}
            <div className={detail ? 'flex min-h-0 flex-1 flex-col' : 'space-y-4'}>
                {detail && (
                    <Tabs
                        value={detailTab}
                        onValueChange={setDetailTab}
                        className="shrink-0 overflow-x-auto border-b px-5 py-3"
                    >
                        <TabsList aria-label={t('User details')}>
                            <TabsTrigger
                                value="basic"
                                id={`${detailTabsId}-basic`}
                                aria-controls={`${detailTabsId}-panel`}
                            >
                                {t('Basic information')}
                            </TabsTrigger>
                            {canViewFunds && (
                                <TabsTrigger
                                    value="funds"
                                    id={`${detailTabsId}-funds`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('User fund flows')}
                                </TabsTrigger>
                            )}
                            {canViewKyc && (
                                <TabsTrigger
                                    value="kyc"
                                    id={`${detailTabsId}-kyc`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Verification')}
                                </TabsTrigger>
                            )}
                            {canViewTopups && (
                                <TabsTrigger
                                    value="deposit"
                                    id={`${detailTabsId}-deposit`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Deposit orders')}
                                </TabsTrigger>
                            )}
                            {financialAccess.withdrawals && (
                                <TabsTrigger
                                    value="withdrawal"
                                    id={`${detailTabsId}-withdrawal`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Withdrawal orders')}
                                </TabsTrigger>
                            )}
                            {users.data[0]?.partnerId && (
                                <TabsTrigger
                                    value="stock"
                                    id={`${detailTabsId}-stock`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Partner stock')}
                                </TabsTrigger>
                            )}
                        </TabsList>
                    </Tabs>
                )}
                {supportError && <OperationFeedback role="alert">{supportError}</OperationFeedback>}
                <UserRecordsView
                    detail={detail}
                    detailTabsId={detailTabsId}
                    detailTab={detailTab}
                    detailContent={
                        detail && users.data[0] ? (
                            detailTab === 'stock' && users.data[0].partnerId ? (
                                <div
                                    className="min-h-0 flex-1 overflow-y-auto p-4"
                                    data-detail-body
                                >
                                    <PartnerHierarchyPanel
                                        key={users.data[0].partnerId}
                                        initialView="stock"
                                        partner={users.data[0].partnerId}
                                        company={users.data[0].companyId}
                                        onClose={() => setDetailTab('basic')}
                                    />
                                </div>
                            ) : detailTab === 'funds' && canViewFunds ? (
                                <UserFundsDrawer
                                    embedded
                                    target={{
                                        company: users.data[0].companyId,
                                        user: users.data[0].id,
                                    }}
                                    trigger={kycTrigger}
                                    onClose={() => setDetailTab('basic')}
                                />
                            ) : detailTab === 'kyc' && canViewKyc ? (
                                <UserKycDrawer
                                    embedded
                                    target={{
                                        company: users.data[0].companyId,
                                        user: users.data[0].id,
                                    }}
                                    trigger={kycTrigger}
                                    onClose={() => setDetailTab('basic')}
                                />
                            ) : (detailTab === 'deposit' && canViewTopups) ||
                              (detailTab === 'withdrawal' && financialAccess.withdrawals) ? (
                                <UserOrdersPanel
                                    key={detailTab}
                                    user={users.data[0]}
                                    mode={detailTab === 'deposit' ? 'deposit' : 'withdrawal'}
                                />
                            ) : null
                        ) : null
                    }
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={users}
                    filters={filters}
                    url="/platform/users"
                    searchLabel={t('Search name, account ID or email')}
                    statuses={['ACTIVE', 'SUSPENDED', 'DISABLED']}
                    selectFilters={[
                        {
                            key: 'partner',
                            label: 'Partner status',
                            allLabel: 'All partner statuses',
                            values: ['Enabled', 'Disabled'],
                            valueLabels: {
                                Enabled: 'Partner users',
                                Disabled: 'Non-partner users',
                            },
                        },
                        {
                            key: 'support',
                            label: 'Support agent',
                            allLabel: 'All users',
                            values: ['Enabled', 'Disabled'],
                            valueLabels: {
                                Enabled: 'Enabled',
                                Disabled: 'Disabled',
                            },
                        },
                    ]}
                    columns={[
                        {
                            label: detail ? 'Email' : 'Company / User',
                            className:
                                'sticky left-0 z-10 w-52 min-w-52 max-w-52 bg-surface shadow-[1px_0_0_var(--color-border)]',
                            render: (row) =>
                                detail ? (
                                    <span className="break-all">{row.email || '—'}</span>
                                ) : (
                                    <UserInfoCell user={row} />
                                ),
                        },
                        {
                            label: 'Agent level',
                            render: (row) =>
                                detail ? (
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
                                ) : (
                                    <span>
                                        {row.promotionRank
                                            ? t('Mastercard level {{rank}}', {
                                                  rank: row.promotionRank,
                                              })
                                            : t(
                                                  row.ordinaryMember
                                                      ? 'Ordinary member'
                                                      : 'Registered member',
                                              )}
                                    </span>
                                ),
                        },
                        ...(detail
                            ? [
                                  {
                                      label: 'Promotion team total',
                                      render: (row: PlatformUser) =>
                                          t('{{count}} people', {
                                              count: row.promotionTeamCount ?? 0,
                                          }),
                                  },
                              ]
                            : []),
                        ...(financialAccess.balances
                            ? [
                                  {
                                      label: 'Available balance',
                                      render: (row: PlatformUser) => (
                                          <WalletAmounts
                                              wallets={row.wallets}
                                              field="available"
                                              hideUsdt={!detail}
                                          />
                                      ),
                                  },
                              ]
                            : []),
                        ...(financialAccess.receipts
                            ? [
                                  {
                                      label: 'Actual deposits',
                                      render: (row: PlatformUser) => (
                                          <ReceiptAmounts
                                              totals={row.receiptTotals}
                                              hideUsdt={!detail}
                                              field="actual"
                                          />
                                      ),
                                  },
                                  {
                                      label: 'Cumulative advances',
                                      render: (row: PlatformUser) => (
                                          <ReceiptAmounts
                                              totals={row.receiptTotals}
                                              hideUsdt={!detail}
                                              field="advance"
                                          />
                                      ),
                                  },
                              ]
                            : []),
                        ...(detail && financialAccess.commission
                            ? [
                                  {
                                      label: 'Cumulative commission',
                                      render: (row: PlatformUser) => (
                                          <MoneyDisplay amount={row.commission!} asset="USDT" />
                                      ),
                                  },
                              ]
                            : []),
                        ...(financialAccess.withdrawals
                            ? [
                                  {
                                      label: 'Withdrawal amount',
                                      render: (row: PlatformUser) => (
                                          <span
                                              title={t(
                                                  'Successful withdrawal amounts including fees.',
                                              )}
                                          >
                                              <MoneyDisplay
                                                  amount={row.totalWithdrawn!}
                                                  hideSymbol={!detail}
                                                  asset="USDT"
                                              />
                                          </span>
                                      ),
                                  },
                              ]
                            : []),
                        ...(detail && financialAccess.balances
                            ? [
                                  {
                                      label: 'Held amount',
                                      render: (row: PlatformUser) => (
                                          <WalletAmounts wallets={row.wallets} field="held" />
                                      ),
                                  },
                                  {
                                      label: 'Security deposit',
                                      render: (row: PlatformUser) => (
                                          <MoneyDisplay
                                              amount={row.securityDeposit!}
                                              asset="USDT"
                                          />
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
                        ...(detail
                            ? [
                                  {
                                      label: 'Customer remark',
                                      render: (row: PlatformUser) => (
                                          <div className="flex min-w-0 items-start gap-3">
                                              <span className="min-w-0 whitespace-pre-wrap break-words">
                                                  {row.remark || '—'}
                                              </span>
                                              {canRemark && (
                                                  <Button
                                                      variant="secondary"
                                                      size="sm"
                                                      className="shrink-0 whitespace-nowrap"
                                                      onClick={() => setRemarkUser(row)}
                                                  >
                                                      {t('Edit customer remark')}
                                                  </Button>
                                              )}
                                          </div>
                                      ),
                                  },
                              ]
                            : []),
                        ...(detail
                            ? [
                                  {
                                      label: 'Invitation code',
                                      render: (row: PlatformUser) => (
                                          <div className="flex min-w-0 items-center gap-3">
                                              <span className="tabular-nums">
                                                  {row.invitationCode || '—'}
                                              </span>
                                              {canChangeInvitation && row.invitationCode && (
                                                  <Button
                                                      variant="secondary"
                                                      size="sm"
                                                      className="shrink-0 whitespace-nowrap"
                                                      onClick={(event) =>
                                                          openAdminEditor(
                                                              `/platform/tenants/${row.companyId}/users/${row.id}/invitation-code`,
                                                              event.currentTarget,
                                                          )
                                                      }
                                                  >
                                                      {t('Change invitation code')}
                                                  </Button>
                                              )}
                                          </div>
                                      ),
                                  },
                              ]
                            : []),
                        ...(!detail
                            ? [
                                  {
                                      label: 'Actions',
                                      render: (row: PlatformUser) => (
                                          <UserInfoCell user={row} action />
                                      ),
                                  },
                              ]
                            : canManageRestrictions ||
                                canManageSupport ||
                                canAdjustWallet ||
                                canChangeReferrer ||
                                canAdjustCommission
                              ? [
                                    {
                                        label: 'Actions',
                                        render: (row: PlatformUser) => (
                                            <div className="flex items-center gap-2">
                                                {(canManageRestrictions ||
                                                    canManageSupport ||
                                                    canAdjustWallet ||
                                                    canChangeReferrer ||
                                                    canAdjustCommission) && (
                                                    <div
                                                        className="flex flex-wrap gap-2"
                                                        onFocus={(event) => {
                                                            kycTrigger.current = event.target;
                                                        }}
                                                    >
                                                        {canManageRestrictions && (
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    setRestrictionsUser(row)
                                                                }
                                                            >
                                                                {t('Operation restrictions')}
                                                            </Button>
                                                        )}
                                                        {canManageSupport && (
                                                            <Button
                                                                variant="secondary"
                                                                disabled={
                                                                    supportBusy ||
                                                                    (!row.supportAgent &&
                                                                        row.status !== 'ACTIVE')
                                                                }
                                                                onClick={() => {
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
                                                            </Button>
                                                        )}
                                                        {canAdjustWallet && (
                                                            <Button variant="secondary" asChild>
                                                                <Link
                                                                    href={`/platform/tenants/${row.companyId}/users/${row.id}/wallet-adjustments`}
                                                                >
                                                                    {t('Wallet adjustment')}
                                                                </Link>
                                                            </Button>
                                                        )}
                                                        {canChangeReferrer && (
                                                            <Button variant="secondary" asChild>
                                                                <Link
                                                                    href={`/platform/tenants/${row.companyId}/users/${row.id}/referrer`}
                                                                >
                                                                    {t('Change referrer')}
                                                                </Link>
                                                            </Button>
                                                        )}
                                                        {canAdjustCommission && (
                                                            <Button variant="secondary" asChild>
                                                                <Link
                                                                    href={`/platform/tenants/${row.companyId}/users/${row.id}/manual-commissions`}
                                                                >
                                                                    {t('Adjust commission')}
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
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
                <CustomerRemarkDialog
                    onSaved={() => router.reload()}
                    user={remarkUser}
                    onClose={() => setRemarkUser(null)}
                />
            )}
        </Layout>
    );
}

function WalletAmounts({
    wallets,
    field,
    hideUsdt = false,
}: {
    wallets?: Wallet[];
    field: 'available' | 'held';
    hideUsdt?: boolean;
}) {
    return wallets?.length ? (
        <div className="space-y-2">
            {wallets.map((wallet) => (
                <div key={wallet.id} className="flex min-h-6 items-center">
                    <MoneyDisplay
                        amount={wallet[field]}
                        asset={wallet.asset}
                        hideSymbol={hideUsdt && wallet.asset === 'USDT'}
                    />
                </div>
            ))}
        </div>
    ) : (
        <span className="text-muted-foreground">—</span>
    );
}

function DetailLayout({ children }: { children: ReactNode; title: string; actions?: ReactNode }) {
    return <Fragment>{children}</Fragment>;
}
function UserRecordsView({
    detail,
    detailTab,
    detailTabsId,
    detailContent,
    ...props
}: Parameters<typeof PlatformAccountTable<PlatformUser>>[0] & {
    detail: boolean;
    detailTab: string;
    detailTabsId: string;
    detailContent: ReactNode;
}) {
    if (!detail) return <PlatformAccountTable {...props} />;
    const row = props.page.data[0];
    if (!row) return null;
    const actions = props.columns.find((column) => column.label === 'Actions');
    return (
        <>
            {detailTab === 'basic' ? (
                <div
                    className="min-h-0 flex-1 overflow-y-auto p-5"
                    role="tabpanel"
                    id={`${detailTabsId}-panel`}
                    aria-labelledby={`${detailTabsId}-${detailTab}`}
                    aria-label={t('Basic information')}
                >
                    <div className="divide-y divide-border">
                        {[
                            {
                                title: 'Account information',
                                labels: [
                                    'Email',
                                    'Status',
                                    'Created',
                                    'Last login',
                                    'Customer remark',
                                ],
                            },
                            {
                                title: 'Funds information',
                                labels: [
                                    'Available balance',
                                    'Actual deposits',
                                    'Cumulative advances',
                                    'Cumulative commission',
                                    'Withdrawal amount',
                                    'Held amount',
                                    'Security deposit',
                                ],
                            },
                            {
                                title: 'Promotion information',
                                labels: ['Agent level', 'Promotion team total', 'Invitation code'],
                            },
                        ].map((group) => (
                            <section
                                key={group.title}
                                className="space-y-4 py-5 first:pt-0 last:pb-0"
                                aria-label={t(group.title)}
                            >
                                <h3 className="text-lg font-semibold">{t(group.title)}</h3>
                                <dl className="grid grid-cols-1 gap-x-6 gap-y-3 min-[480px]:grid-cols-2 sm:grid-cols-3 xl:grid-cols-4">
                                    {props.columns
                                        .filter((column) => group.labels.includes(column.label))
                                        .map((column) => (
                                            <div
                                                key={column.label}
                                                className={`flex min-w-0 items-start gap-1 ${['Customer remark', 'Invitation code'].includes(column.label) ? 'col-span-full items-center' : ''}`}
                                            >
                                                <dt className="shrink-0 text-sm text-muted-foreground">
                                                    {t(column.label)}：
                                                </dt>
                                                <dd className="min-w-0 flex-1 break-words text-sm">
                                                    {column.render(row)}
                                                </dd>
                                            </div>
                                        ))}
                                </dl>
                                {group.title === 'Promotion information' && (
                                    <>
                                        <section
                                            className="mt-5 space-y-2 text-sm"
                                            aria-label={t('Upstream agent chain')}
                                        >
                                            <h3 className="text-muted-foreground">
                                                {t('Upstream agent chain')}
                                            </h3>
                                            {row.ancestors?.length ? (
                                                <ol className="space-y-2">
                                                    {row.ancestors.map((ancestor, index) => (
                                                        <li
                                                            key={ancestor.id}
                                                            className="flex flex-wrap items-baseline gap-x-3 gap-y-1"
                                                        >
                                                            <span className="text-xs text-muted-foreground">
                                                                {ancestor.distance === 1
                                                                    ? t('Direct referrer')
                                                                    : t(
                                                                          'Ancestor level {{level}}',
                                                                          {
                                                                              level: ancestor.distance,
                                                                          },
                                                                      )}
                                                            </span>
                                                            <span className="font-medium">
                                                                {ancestor.displayName ||
                                                                    ancestor.email ||
                                                                    ancestor.accountId}
                                                            </span>
                                                            <span className="text-xs text-muted-foreground">
                                                                {ancestor.accountId}
                                                            </span>
                                                            <span className="text-primary">
                                                                {ancestor.promotionRank
                                                                    ? t(
                                                                          'Mastercard level {{rank}}',
                                                                          {
                                                                              rank: ancestor.promotionRank,
                                                                          },
                                                                      )
                                                                    : t(
                                                                          ancestor.ordinaryMember
                                                                              ? 'Ordinary member'
                                                                              : 'Registered member',
                                                                      )}
                                                            </span>
                                                            {index ===
                                                                row.ancestors!.length - 1 && (
                                                                <span className="text-xs text-muted-foreground">
                                                                    {t('Topmost referrer')}
                                                                </span>
                                                            )}
                                                        </li>
                                                    ))}
                                                </ol>
                                            ) : (
                                                <p className="text-muted-foreground">
                                                    {t('No upstream agent')}
                                                </p>
                                            )}
                                        </section>
                                    </>
                                )}
                            </section>
                        ))}
                    </div>
                </div>
            ) : (
                <div
                    className="flex min-h-0 flex-1 flex-col"
                    role="tabpanel"
                    id={`${detailTabsId}-panel`}
                    aria-labelledby={`${detailTabsId}-${detailTab}`}
                >
                    {detailContent}
                </div>
            )}
            {actions && (
                <div className="shrink-0 border-t bg-surface p-5" aria-label={t('Actions')}>
                    {actions.render(row)}
                </div>
            )}
        </>
    );
}

function ReceiptAmounts({
    totals,
    field,
    hideUsdt = false,
}: {
    totals?: PlatformUser['receiptTotals'];
    hideUsdt?: boolean;
    field: 'actual' | 'advance';
}) {
    return (
        <div className="space-y-2">
            {totals?.map((row) => (
                <div key={row.asset}>
                    <MoneyDisplay
                        amount={row[field]}
                        asset={row.asset}
                        hideSymbol={hideUsdt && row.asset === 'USDT'}
                    />
                </div>
            ))}
        </div>
    );
}
