import { UserSnapshotExport } from './UserSnapshotExport';
import { Input } from '@/components/ui/input';
import { errorMessage } from '@/i18n/admin';
import { UserAssetSummaryDialog, SummaryAmount, AssetAmounts } from './UserAssetSummaryDialog';
import { Pencil } from 'lucide-react';
import { EnablePartnerDialog } from './EnablePartnerDialog';
import { ReferrerFilterSelect } from './ReferrerFilterSelect';
import { PartnerHierarchyPanel } from './PartnerHierarchyPanel';
import { UserInfoCell } from '@/components/admin/UserInfoCell';
import { Fragment, type ReactNode } from 'react';
import { Tabs, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { UserOrdersPanel } from './UserOrdersPanel';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { UserRestrictionsDialog } from '@/components/admin/UserRestrictionsDialog';
import { userRestrictionLabels, type OperationRestrictions } from './user-operation-restrictions';
import { DataCleanupDialog } from '@/components/admin/DataCleanupDialog';
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
    directReferrer?: { accountId: string; displayName: string | null } | null;
    financialSummary?: {
        estimate?: string | null;
        cardBalance?: string | null;
        annualCommission?: string;
        activationCommission?: string;
        annualExpense?: string;
        transfers?: {
            incoming: { asset: string; amount: string }[];
            outgoing: { asset: string; amount: string }[];
        };
        withdrawals?: { asset: string; amount: string }[];
    };
    partnerId?: string | null;
    promotionTeamCount?: number;
    descendantCount?: number;
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
    operationRestrictions?: OperationRestrictions;
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
    readOnly = false,
    snapshotAt = null,
    snapshotEarliest = null,
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
    canManagePartners,
    canCreateUser,
    canCleanup = false,
    canRemark,
    canManageRestrictions,
}: {
    detail?: boolean;
    readOnly?: boolean;
    snapshotAt?: string | null;
    snapshotEarliest?: string | null;
    canManageRestrictions: boolean;
    canRemark: boolean;
    canManagePartners?: boolean;
    canCreateUser: boolean;
    canCleanup?: boolean;
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
        referrer?: string;
        as_of?: string;
    };
    financialAccess: {
        receipts: boolean;
        balances: boolean;
        commission: boolean;
        withdrawals: boolean;
        funds?: boolean;
    };
}) {
    useAdminTranslation();
    const [assetTarget, setAssetTarget] = useState<
        (PlatformUser & { view: 'funds' | 'cards' }) | null
    >(null);
    const [partnerUser, setPartnerUser] = useState<PlatformUser | null>(null);
    const [detailTab, setDetailTab] = useState('basic');
    const detailTabsId = useId();
    const [restrictionsUser, setRestrictionsUser] = useState<PlatformUser | null>(null);
    const [remarkUser, setRemarkUser] = useState<PlatformUser | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [cleanup, setCleanup] = useState<{ user?: PlatformUser } | null>(null);
    const [supportBusy, setSupportBusy] = useState(false);
    const [supportError, setSupportError] = useState('');
    const { url, props } = usePage();
    const cutoffError = props.errors?.as_of;
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
                    {!detail && <UserSnapshotExport filters={filters} />}
                    {!readOnly && canCleanup && !detail && (
                        <Button variant="secondary" onClick={() => setCleanup({})}>
                            {t('Cleanup data')}
                        </Button>
                    )}
                    {!readOnly && canCreateUser && (
                        <Button onClick={() => setCreateOpen(true)}>{t('Add account')}</Button>
                    )}
                </>
            }
        >
            <UserAssetSummaryDialog
                key={assetTarget ? `${assetTarget.id}-${assetTarget.view}` : 'closed'}
                target={assetTarget}
                asOf={snapshotAt}
                onClose={() => setAssetTarget(null)}
            />
            {!detail && <Head title={t('Users')} />}
            {!readOnly && cleanup && (
                <DataCleanupDialog
                    companies={companies}
                    company={filters.company}
                    initialUser={cleanup.user}
                    onClose={() => setCleanup(null)}
                />
            )}
            {!readOnly && partnerUser && (
                <EnablePartnerDialog
                    user={partnerUser}
                    onClose={() => setPartnerUser(null)}
                    onSaved={() => {
                        setPartnerUser(null);
                        router.reload();
                    }}
                />
            )}
            {!readOnly && restrictionsUser && (
                <UserRestrictionsDialog
                    key={restrictionsUser.id}
                    user={restrictionsUser}
                    onClose={() => setRestrictionsUser(null)}
                    onSaved={() => router.reload({ only: ['users'] })}
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
                            {!readOnly && canViewFunds && (
                                <TabsTrigger
                                    value="funds"
                                    id={`${detailTabsId}-funds`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('User fund flows')}
                                </TabsTrigger>
                            )}
                            {!readOnly && canViewKyc && (
                                <TabsTrigger
                                    value="kyc"
                                    id={`${detailTabsId}-kyc`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Verification')}
                                </TabsTrigger>
                            )}
                            {!readOnly && canViewTopups && (
                                <TabsTrigger
                                    value="deposit"
                                    id={`${detailTabsId}-deposit`}
                                    aria-controls={`${detailTabsId}-panel`}
                                >
                                    {t('Deposit orders')}
                                </TabsTrigger>
                            )}
                            {!readOnly && financialAccess.withdrawals && (
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
                {(!detail || snapshotAt) && (
                    <div className="space-y-1 text-sm text-muted-foreground" role="status">
                        <p>
                            {snapshotAt
                                ? t('Read-only snapshot at {{time}} (UTC+8)', {
                                      time: snapshotAt.replace('T', ' '),
                                  })
                                : t('Current user data')}
                        </p>
                        {snapshotEarliest && (
                            <p>
                                {t('Historical snapshots available from {{time}} (UTC+8)', {
                                    time: snapshotEarliest.replace('T', ' '),
                                })}
                            </p>
                        )}
                    </div>
                )}
                {cutoffError && (
                    <OperationFeedback role="alert">{errorMessage(cutoffError)}</OperationFeedback>
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
                    statuses={['ACTIVE', 'SUSPENDED', 'DISABLED', 'DEPOSIT_REFUNDING']}
                    textFilters={[
                        {
                            key: 'as_of',
                            label: 'Cutoff time (UTC+8)',
                            placeholder: 'Cutoff time (UTC+8)',
                            defaultValue: `${new Intl.DateTimeFormat('en-CA', {
                                timeZone: 'Asia/Shanghai',
                                year: 'numeric',
                                month: '2-digit',
                                day: '2-digit',
                            }).format(new Date())}T00:00:00`,
                            render: ({ value, onChange }) => (
                                <Input
                                    type="datetime-local"
                                    step={1}
                                    min={snapshotEarliest ?? undefined}
                                    aria-label={t('Cutoff time (UTC+8)')}
                                    value={value}
                                    onChange={(event) =>
                                        onChange(
                                            event.target.value.length === 16
                                                ? `${event.target.value}:00`
                                                : event.target.value,
                                        )
                                    }
                                />
                            ),
                        },
                        {
                            key: 'referrer',
                            label: 'Filter by referrer',
                            placeholder: 'Referrer account ID or email',
                            render: (props) => (
                                <ReferrerFilterSelect {...props} asOf={snapshotAt} />
                            ),
                        },
                    ]}
                    selectFilters={[
                        {
                            key: 'support',
                            advanced: true,
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
                            pin: 'left',
                            className:
                                'sticky left-0 z-10 w-52 min-w-52 max-w-52 bg-surface shadow-[1px_0_0_var(--color-border)]',
                            render: (row) =>
                                detail ? (
                                    <span className="break-all">{row.email || '—'}</span>
                                ) : (
                                    <UserInfoCell user={row} asOf={snapshotAt} />
                                ),
                        },
                        {
                            label: 'Agent level',
                            render: (row) =>
                                detail && !readOnly ? (
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
                        ...(!detail
                            ? [
                                  {
                                      label: 'Direct referrer',
                                      render: (row: PlatformUser) =>
                                          row.directReferrer ? (
                                              <div>
                                                  {row.directReferrer.displayName || '—'}
                                                  <div className="text-xs text-muted-foreground">
                                                      {row.directReferrer.accountId}
                                                  </div>
                                              </div>
                                          ) : (
                                              '—'
                                          ),
                                  },
                              ]
                            : []),
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
                        ...(!detail && financialAccess.funds
                            ? [
                                  {
                                      label: 'Estimated total funds',
                                      render: (row: PlatformUser) => (
                                          <button
                                              className="text-primary underline underline-offset-4"
                                              onClick={() =>
                                                  setAssetTarget({ ...row, view: 'funds' })
                                              }
                                          >
                                              <SummaryAmount
                                                  amount={row.financialSummary?.estimate}
                                              />
                                          </button>
                                      ),
                                  },
                                  {
                                      label: 'Card balance',
                                      render: (row: PlatformUser) => (
                                          <button
                                              className="text-primary underline underline-offset-4"
                                              onClick={() =>
                                                  setAssetTarget({ ...row, view: 'cards' })
                                              }
                                          >
                                              <SummaryAmount
                                                  amount={row.financialSummary?.cardBalance}
                                              />
                                          </button>
                                      ),
                                  },
                              ]
                            : []),
                        ...(financialAccess.receipts
                            ? [
                                  {
                                      label: detail ? 'Actual deposits' : 'Actual deposit amount',
                                      render: (row: PlatformUser) => (
                                          <ReceiptAmounts
                                              totals={row.receiptTotals}
                                              hideUsdt={!detail}
                                              field="actual"
                                          />
                                      ),
                                  },
                                  ...(detail
                                      ? [
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
                              ]
                            : []),
                        ...(!detail && financialAccess.commission
                            ? [
                                  {
                                      label: 'Annual commission',
                                      render: (row: PlatformUser) => (
                                          <SummaryAmount
                                              amount={row.financialSummary?.annualCommission}
                                          />
                                      ),
                                  },
                                  {
                                      label: 'Activation commission',
                                      render: (row: PlatformUser) => (
                                          <SummaryAmount
                                              amount={row.financialSummary?.activationCommission}
                                          />
                                      ),
                                  },
                                  {
                                      label: 'Annual fee expense',
                                      render: (row: PlatformUser) => (
                                          <SummaryAmount
                                              amount={row.financialSummary?.annualExpense}
                                          />
                                      ),
                                  },
                                  {
                                      label: 'Transfers',
                                      render: (row: PlatformUser) => (
                                          <div className="space-y-2">
                                              <div>
                                                  {t('Incoming')}
                                                  <AssetAmounts
                                                      items={
                                                          row.financialSummary?.transfers?.incoming
                                                      }
                                                  />
                                              </div>
                                              <div>
                                                  {t('Outgoing')}
                                                  <AssetAmounts
                                                      items={
                                                          row.financialSummary?.transfers?.outgoing
                                                      }
                                                  />
                                              </div>
                                          </div>
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
                                      label: detail ? 'Withdrawal amount' : 'Outgoing funds',
                                      render: (row: PlatformUser) =>
                                          !detail ? (
                                              <AssetAmounts
                                                  items={row.financialSummary?.withdrawals}
                                              />
                                          ) : (
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
                                <div className="space-y-1">
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
                                    {canManageRestrictions &&
                                        (
                                            Object.keys(
                                                userRestrictionLabels,
                                            ) as (keyof OperationRestrictions)[]
                                        ).map((field) =>
                                            row.operationRestrictions?.[field] ? (
                                                <p
                                                    key={field}
                                                    className="whitespace-nowrap text-xs text-black"
                                                >
                                                    {t(userRestrictionLabels[field])}
                                                </p>
                                            ) : null,
                                        )}
                                </div>
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
                                          <div className="flex min-w-0 items-center gap-1">
                                              <span className="min-w-0 whitespace-pre-wrap break-words">
                                                  {row.remark || '—'}
                                              </span>
                                              {!readOnly && canRemark && (
                                                  <Button
                                                      variant="ghost"
                                                      size="icon"
                                                      className="size-6 min-h-6 shrink-0 text-muted-foreground"
                                                      aria-label={t('Edit customer remark')}
                                                      title={t('Edit customer remark')}
                                                      onClick={() => setRemarkUser(row)}
                                                  >
                                                      <Pencil
                                                          className="size-3.5"
                                                          aria-hidden="true"
                                                      />
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
                                          <div className="flex min-w-0 items-center gap-1">
                                              <span className="tabular-nums">
                                                  {row.invitationCode || '—'}
                                              </span>
                                              {!readOnly &&
                                                  canChangeInvitation &&
                                                  row.invitationCode && (
                                                      <Button
                                                          variant="ghost"
                                                          size="icon"
                                                          className="size-6 min-h-6 shrink-0 text-muted-foreground"
                                                          aria-label={t('Change invitation code')}
                                                          title={t('Change invitation code')}
                                                          onClick={(event) =>
                                                              openAdminEditor(
                                                                  `/platform/tenants/${row.companyId}/users/${row.id}/invitation-code`,
                                                                  event.currentTarget,
                                                              )
                                                          }
                                                      >
                                                          <Pencil
                                                              className="size-3.5"
                                                              aria-hidden="true"
                                                          />
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
                                      pin: 'right' as const,
                                      className:
                                          'sticky right-0 z-10 bg-surface shadow-[-1px_0_0_var(--color-border)]',
                                      render: (row: PlatformUser) => (
                                          <div className="flex items-center gap-2 whitespace-nowrap">
                                              <UserInfoCell user={row} action asOf={snapshotAt} />
                                              <Button
                                                  variant="secondary"
                                                  size="sm"
                                                  onClick={() =>
                                                      router.get(
                                                          '/platform/users',
                                                          {
                                                              company: row.companyId,
                                                              referrer: row.accountId,
                                                              as_of: snapshotAt ?? undefined,
                                                          },
                                                          { preserveState: true },
                                                      )
                                                  }
                                              >
                                                  {t('Subordinates ({{count}})', {
                                                      count: row.descendantCount ?? 0,
                                                  })}
                                              </Button>
                                          </div>
                                      ),
                                  },
                              ]
                            : canCleanup ||
                                canManagePartners ||
                                canManageRestrictions ||
                                canManageSupport ||
                                canAdjustWallet ||
                                canChangeReferrer ||
                                canAdjustCommission
                              ? [
                                    {
                                        label: 'Actions',
                                        render: (row: PlatformUser) => (
                                            <div className="flex items-center gap-2">
                                                {(canCleanup ||
                                                    canManagePartners ||
                                                    canManageRestrictions ||
                                                    canManageSupport ||
                                                    canAdjustWallet ||
                                                    canChangeReferrer ||
                                                    canAdjustCommission) &&
                                                    !readOnly && (
                                                        <div
                                                            className="flex flex-wrap gap-2"
                                                            onFocus={(event) => {
                                                                kycTrigger.current = event.target;
                                                            }}
                                                        >
                                                            {canManagePartners &&
                                                                !row.partnerId && (
                                                                    <Button
                                                                        variant="secondary"
                                                                        onClick={() =>
                                                                            setPartnerUser(row)
                                                                        }
                                                                    >
                                                                        {t('Set as partner')}
                                                                    </Button>
                                                                )}
                                                            {canCleanup && (
                                                                <Button
                                                                    variant="secondary"
                                                                    onClick={() =>
                                                                        setCleanup({ user: row })
                                                                    }
                                                                >
                                                                    {t('Cleanup data')}
                                                                </Button>
                                                            )}
                                                            {!readOnly && canManageRestrictions && (
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
                                                                                setSupportBusy(
                                                                                    false,
                                                                                );
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
            {!readOnly && remarkUser && (
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
                                <dl className="grid grid-cols-[repeat(auto-fit,minmax(min(100%,200px),1fr))] gap-x-6 gap-y-3">
                                    {props.columns
                                        .filter((column) => group.labels.includes(column.label))
                                        .map((column) => (
                                            <div
                                                key={column.label}
                                                className={`flex min-w-0 gap-1 ${['Customer remark', 'Invitation code'].includes(column.label) ? 'col-span-full items-center' : 'items-start'}`}
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
