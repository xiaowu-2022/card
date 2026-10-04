import {
    CardTransactionBatchSync,
    type SyncScope,
} from '@/components/admin/CardTransactionBatchSync';
import { AdminCardReveal } from '@/components/admin/AdminCardReveal';
import { CardOverflowSpend } from '@/components/admin/CardOverflowSpend';
import { CardBalanceLimit } from '@/components/admin/CardBalanceLimit';
import { CardLoadOrders, type CardLoadOrder } from '@/components/admin/CardLoadOrders';
import type { SharedProps } from '@/types/global';
import { PlatformCardTransactions } from '@/components/admin/PlatformCardTransactions';
import { displayMoney } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { useEffect, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
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
    lastTransactionSyncAt: string | null;
    companyName: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
    formFactor: string;
    produceStatus: string | null;
    trackingNumber: string | null;
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
    const tab = filters.tab ?? 'orders';
    const [selected, setSelected] = useState<Record<string, UserCard>>({});
    const [syncScope, setSyncScope] = useState<SyncScope | null>(null);
    const [transactionRefresh, setTransactionRefresh] = useState(0);
    useEffect(() => {
        setSelected({});
    }, [filters.company, filters.search]);
    const selection = Object.values(selected);
    const pageSelected = cards.data.length > 0 && cards.data.every((card) => selected[card.id]);
    function selectPage(checked: boolean) {
        setSelected((previous) => {
            const next = { ...previous };
            for (const card of cards.data) {
                if (!checked) delete next[card.id];
                else if (Object.keys(next).length < 500) next[card.id] = card;
            }
            return next;
        });
    }
    function completedSync() {
        router.reload({
            only: ['cards'],
            onSuccess: () => setTransactionRefresh((value) => value + 1),
        });
    }
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
                <Tabs
                    value={tab}
                    onValueChange={(value) =>
                        router.get(
                            '/platform/cards',
                            { ...filters, tab: value },
                            { preserveState: true, preserveScroll: true, replace: true },
                        )
                    }
                >
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
                        {canManage && (
                            <>
                                <div className="mb-3 flex flex-wrap items-center gap-2">
                                    <Button
                                        onClick={() => setSyncScope({ company: filters.company })}
                                    >
                                        {t('Bulk sync card transactions')}
                                    </Button>
                                    <Button
                                        variant="secondary"
                                        disabled={!selection.length}
                                        onClick={() => setSyncScope({ cards: selection })}
                                    >
                                        {t('Sync selected cards ({{count}})', {
                                            count: selection.length,
                                        })}
                                    </Button>
                                    {selection.length > 0 && (
                                        <Button variant="ghost" onClick={() => setSelected({})}>
                                            {t('Clear selection')}
                                        </Button>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {t(
                                            'Select up to 500 cards across pages. Changing filters clears the selection.',
                                        )}
                                    </span>
                                </div>
                                <CardTransactionBatchSync
                                    companies={companies}
                                    scope={syncScope}
                                    onClose={() => setSyncScope(null)}
                                    onCompleted={completedSync}
                                />
                            </>
                        )}
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
                                ...(canManage
                                    ? [
                                          {
                                              label: 'Select',
                                              header: (
                                                  <Checkbox
                                                      aria-label={t('Select this page')}
                                                      checked={
                                                          pageSelected
                                                              ? true
                                                              : cards.data.some(
                                                                      (card) => selected[card.id],
                                                                  )
                                                                ? 'indeterminate'
                                                                : false
                                                      }
                                                      onCheckedChange={(checked) =>
                                                          selectPage(checked === true)
                                                      }
                                                  />
                                              ),
                                              render: (row: UserCard) => (
                                                  <Checkbox
                                                      aria-label={t('Select card {{card}}', {
                                                          card: row.maskedPan,
                                                      })}
                                                      checked={Boolean(selected[row.id])}
                                                      disabled={
                                                          !selected[row.id] &&
                                                          selection.length >= 500
                                                      }
                                                      onCheckedChange={(checked) =>
                                                          setSelected((previous) => {
                                                              const next = { ...previous };
                                                              if (checked) next[row.id] = row;
                                                              else delete next[row.id];
                                                              return next;
                                                          })
                                                      }
                                                  />
                                              ),
                                          },
                                      ]
                                    : []),

                                {
                                    label: 'Company / User',
                                    render: (row) => (
                                        <div className="max-w-52 space-y-1">
                                            <div
                                                className="truncate font-medium"
                                                title={row.companyName}
                                            >
                                                {row.companyName}
                                            </div>
                                            <div
                                                className="truncate text-xs text-muted-foreground"
                                                title={row.userEmail}
                                            >
                                                {row.userEmail}
                                            </div>
                                        </div>
                                    ),
                                },
                                {
                                    label: 'Product / Card',
                                    render: (row) => (
                                        <div className="max-w-52 space-y-1">
                                            <div
                                                className="truncate font-medium"
                                                title={row.productName}
                                            >
                                                {row.productName}
                                            </div>
                                            <div className="flex items-center gap-2 whitespace-nowrap text-xs">
                                                <span className="font-mono">{row.maskedPan}</span>
                                                <span className="text-muted-foreground">
                                                    {t(
                                                        row.formFactor === 'physical_card'
                                                            ? 'Physical card'
                                                            : 'Virtual card',
                                                    )}
                                                </span>
                                            </div>
                                            {row.produceStatus && (
                                                <span className="block text-xs">
                                                    {t(
                                                        row.produceStatus === 'produced'
                                                            ? 'Card produced'
                                                            : 'Card production pending',
                                                    )}
                                                </span>
                                            )}
                                            {row.trackingNumber && (
                                                <span
                                                    className="block truncate text-xs"
                                                    title={row.trackingNumber}
                                                >
                                                    {t('Tracking number')}: {row.trackingNumber}
                                                </span>
                                            )}
                                        </div>
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
                                    label: 'Last successful sync',
                                    render: (row) => (
                                        <span
                                            className="text-xs"
                                            title={t(
                                                'Latest completed date-range sync. This is separate from balance refresh.',
                                            )}
                                        >
                                            {row.lastTransactionSyncAt
                                                ? dateTime(row.lastTransactionSyncAt)
                                                : t('Not yet synced')}
                                        </span>
                                    ),
                                },
                                {
                                    label: 'Actions',
                                    render: (row) => (
                                        <div className="flex items-center gap-2">
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                onClick={() => setSelectedCard(row)}
                                            >
                                                {t('View transactions')}
                                            </Button>
                                            {canManage && (
                                                <Button
                                                    variant="secondary"
                                                    size="sm"
                                                    onClick={() =>
                                                        setSyncScope({
                                                            company: row.tenantId,
                                                            cards: [row],
                                                        })
                                                    }
                                                >
                                                    {t('Sync')}
                                                </Button>
                                            )}
                                            <CardRowActions
                                                card={row}
                                                canManage={Boolean(canManage)}
                                                refreshing={refreshing !== null}
                                                onRefresh={() => {
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
                                            />
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
                    refreshKey={transactionRefresh}
                    onSync={
                        canManage
                            ? () => {
                                  setSyncScope({
                                      company: selectedCard.tenantId,
                                      cards: [selectedCard],
                                  });
                                  setSelectedCard(null);
                              }
                            : undefined
                    }
                    onClose={() => setSelectedCard(null)}
                />
            )}
        </PlatformLayout>
    );
}

function CardRowActions({
    card,
    canManage,
    refreshing,
    onRefresh,
}: {
    card: UserCard;
    canManage: boolean;
    refreshing: boolean;
    onRefresh: () => void;
}) {
    const [action, setAction] = useState<'reveal' | 'limit' | 'overflow' | null>(null);
    const trigger = useRef<HTMLButtonElement>(null);
    const close = (open: boolean) => {
        if (!open) {
            setAction(null);
            requestAnimationFrame(() => trigger.current?.focus());
        }
    };
    return (
        <>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button ref={trigger} variant="secondary" size="sm">
                        {t('More actions')}
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    align="end"
                    onCloseAutoFocus={(event) => {
                        if (action) event.preventDefault();
                    }}
                >
                    <DropdownMenuItem disabled={refreshing} onSelect={onRefresh}>
                        {t(refreshing ? 'Refreshing' : 'Refresh balance')}
                    </DropdownMenuItem>
                    {canManage && (
                        <>
                            <DropdownMenuItem onSelect={() => setAction('reveal')}>
                                {t('View card information')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setAction('limit')}>
                                {t('Balance limit')}
                            </DropdownMenuItem>
                            <DropdownMenuItem onSelect={() => setAction('overflow')}>
                                {t('Record overflow consumption')}
                            </DropdownMenuItem>
                        </>
                    )}
                </DropdownMenuContent>
            </DropdownMenu>
            {action === 'reveal' && <AdminCardReveal card={card} open onOpenChange={close} />}
            {action === 'limit' && <CardBalanceLimit card={card} open onOpenChange={close} />}
            {action === 'overflow' && <CardOverflowSpend card={card} open onOpenChange={close} />}
        </>
    );
}
