import { CardOverflowSpend } from '@/components/admin/CardOverflowSpend';
import { CardBalanceLimit } from '@/components/admin/CardBalanceLimit';
import { CardLoadOrders, type CardLoadOrder } from '@/components/admin/CardLoadOrders';
import type { SharedProps } from '@/types/global';
import { PlatformCardTransactions } from '@/components/admin/PlatformCardTransactions';
import { displayMoney } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { useState } from 'react';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PlatformLayout } from '@/layouts/PlatformLayout';

type Order = {
    id: string;
    companyName: string;
    userEmail: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    asset: string;
    status: string;
};
type UserCard = {
    id: string;
    tenantId: string;
    balance: string | null;
    overflowBalance: string;
    providerBalance: string | null;
    balanceLimit: string | null;
    effectiveBalanceLimit: string | null;
    currency: string;
    balanceUpdatedAt: string | null;
    companyName: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
    providerStatus: string;
};
const tone = (status: string): StatusTone =>
    status === 'SUCCEEDED' || status === 'normal'
        ? 'SUCCESS'
        : status === 'FAILED'
          ? 'DANGER'
          : status === 'UNKNOWN'
            ? 'WARNING'
            : 'INFO';

export default function Cards({
    orders,
    loads,
    cards,
    companies,
    filters,
}: {
    orders: AccountPage<Order>;
    loads: AccountPage<CardLoadOrder>;
    cards: AccountPage<UserCard>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; tab?: string };
}) {
    useAdminTranslation();
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('card_product.manage');
    const [tab, setTab] = useState(filters.tab ?? 'orders');
    const [selectedCard, setSelectedCard] = useState<UserCard | null>(null);
    const [refreshing, setRefreshing] = useState<string | null>(null);
    const [refreshError, setRefreshError] = useState('');
    return (
        <PlatformLayout>
            <Head title={t('Card operations')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('Cards')}
                    description={t(
                        'Review card orders and balances, and manage individual card balance limits.',
                    )}
                />
                <Tabs value={tab} onValueChange={setTab}>
                    <TabsList>
                        <TabsTrigger value="orders">{t('Issue orders')}</TabsTrigger>
                        <TabsTrigger value="loads">{t('Card reload orders')}</TabsTrigger>
                        <TabsTrigger value="cards">{t('Cards')}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="orders">
                        <PlatformAccountTable
                            key={JSON.stringify(filters)}
                            page={orders}
                            companies={companies}
                            filters={filters}
                            url="/platform/cards"
                            extraQuery={{ tab: 'orders' }}
                            searchLabel={t('Search account ID, email or phone')}
                            columns={[
                                { label: 'Tenant', render: (row) => row.companyName },
                                { label: 'User', render: (row) => row.userEmail },
                                { label: 'Product', render: (row) => row.productName },
                                {
                                    label: 'Amounts',
                                    render: (row) =>
                                        `${displayMoney(row.openingFee)} + ${displayMoney(row.initialLoadAmount)} ${row.asset}`,
                                },
                                {
                                    label: 'Status',
                                    render: (row) => (
                                        <StatusBadge
                                            status={tone(row.status)}
                                            label={t(row.status)}
                                        />
                                    ),
                                },
                            ]}
                        />
                    </TabsContent>
                    <TabsContent value="loads">
                        <CardLoadOrders page={loads} canManage={canManage} />
                    </TabsContent>
                    <TabsContent value="cards">
                        {refreshError && (
                            <p role="alert" className="mb-3 text-sm text-destructive">
                                {t(refreshError)}
                            </p>
                        )}
                        <PlatformAccountTable
                            key={JSON.stringify(filters)}
                            page={cards}
                            companies={companies}
                            filters={filters}
                            url="/platform/cards"
                            extraQuery={{ tab: 'cards' }}
                            searchLabel={t('Search account ID, email or phone')}
                            columns={[
                                { label: 'Tenant', render: (row) => row.companyName },
                                { label: 'User', render: (row) => row.userEmail },
                                { label: 'Product', render: (row) => row.productName },
                                {
                                    label: 'Card',
                                    render: (row) => (
                                        <span className="font-mono">{row.maskedPan}</span>
                                    ),
                                },
                                {
                                    label: 'Current balance',
                                    render: (row) => (
                                        <div className="whitespace-nowrap">
                                            <div className="font-medium tabular-nums">
                                                {row.balance === null
                                                    ? t('Not available')
                                                    : `${displayMoney(row.balance)} ${row.currency}`}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {row.balanceUpdatedAt
                                                    ? dateTime(row.balanceUpdatedAt)
                                                    : t('Not yet synced')}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    label: 'Overflow balance',
                                    render: (row) => `${displayMoney(row.overflowBalance)} USD`,
                                },
                                {
                                    label: 'Provider balance',
                                    render: (row) =>
                                        row.providerBalance === null
                                            ? t('Not available')
                                            : `${displayMoney(row.providerBalance)} USD`,
                                },
                                {
                                    label: 'Balance limit',
                                    render: (row) =>
                                        row.effectiveBalanceLimit === null
                                            ? t('No limit')
                                            : `${displayMoney(row.effectiveBalanceLimit)} USD`,
                                },
                                {
                                    label: 'Status',
                                    render: (row) => (
                                        <StatusBadge
                                            status={tone(row.providerStatus)}
                                            label={t(row.providerStatus)}
                                        />
                                    ),
                                },
                                {
                                    label: 'Actions',
                                    render: (row) => (
                                        <div className="flex flex-wrap gap-2">
                                            {canManage && (
                                                <>
                                                    <CardOverflowSpend card={row} />
                                                    <CardBalanceLimit
                                                        key={`${row.id}:${row.balanceLimit}`}
                                                        card={row}
                                                    />
                                                </>
                                            )}
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                onClick={() => setSelectedCard(row)}
                                            >
                                                {t('View transactions')}
                                            </Button>
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                disabled={refreshing !== null}
                                                onClick={() => {
                                                    setRefreshing(row.id);
                                                    setRefreshError('');
                                                    router.post(
                                                        `/platform/tenants/${row.tenantId}/cards/${row.id}/refresh`,
                                                        {},
                                                        {
                                                            preserveState: true,
                                                            preserveScroll: true,
                                                            onError: (errors) =>
                                                                setRefreshError(
                                                                    errors.card_balance ??
                                                                        'Card balance could not be refreshed. The last confirmed balance is shown.',
                                                                ),
                                                            onFinish: () => setRefreshing(null),
                                                        },
                                                    );
                                                }}
                                            >
                                                {t(
                                                    refreshing === row.id
                                                        ? 'Refreshing'
                                                        : 'Refresh balance',
                                                )}
                                            </Button>
                                        </div>
                                    ),
                                },
                            ]}
                        />
                    </TabsContent>
                </Tabs>
            </div>
            {selectedCard && (
                <PlatformCardTransactions
                    key={`${selectedCard.tenantId}:${selectedCard.id}`}
                    card={selectedCard}
                    onClose={() => setSelectedCard(null)}
                />
            )}
        </PlatformLayout>
    );
}
