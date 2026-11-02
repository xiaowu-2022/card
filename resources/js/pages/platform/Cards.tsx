import { CardVoidBatchDialog, type VoidCard } from '@/components/admin/CardVoidBatchDialog';
import { adminAssetLabel } from '@/lib/admin-asset-label';
import { RecordUserCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
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
import { displayMoney } from '@/lib/admin-amount';
import { useAdminTranslation, t } from '@/i18n/admin';
import { Head, router, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Checkbox } from '@/components/ui/checkbox';
import { useEffect, useRef, useState } from 'react';
import {
    DropdownMenu,
    DropdownMenuTrigger,
    DropdownMenuContent,
    DropdownMenuItem,
} from '@/components/ui/dropdown-menu';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import {
    platformCardColumns,
    platformCardStatusTone,
    type UserCard,
} from '@/components/admin/PlatformCardColumns';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { PlatformLayout } from '@/layouts/PlatformLayout';

type Order = {
    tenantId: string;
    userInfo?: UserInfo;
    userId?: string;
    id: string;
    companyName: string;
    userEmail: string;
    productName: string;
    openingFee: string;
    initialLoadAmount: string;
    asset: string;
    status: string;
};

export default function Cards({
    orders,
    loads,
    cards,
    companies,
    filters,
    canVoidCards = false,
}: {
    canVoidCards?: boolean;
    orders: AccountPage<Order>;
    loads: AccountPage<CardLoadOrder>;
    cards: AccountPage<UserCard>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; tab?: string };
}) {
    useAdminTranslation();
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('card_product.manage');
    const tab = filters.tab ?? 'cards';
    const [selected, setSelected] = useState<Record<string, UserCard>>({});
    const [refreshingIssue, setRefreshingIssue] = useState<string | null>(null);
    const [issueError, setIssueError] = useState<string | null>(null);
    const [syncScope, setSyncScope] = useState<SyncScope | null>(null);
    const [syncOpen, setSyncOpen] = useState(false);
    function openSync(scope: SyncScope) {
        setSyncScope(scope);
        setSyncOpen(true);
    }
    const [voidScope, setVoidScope] = useState<VoidCard[] | null | undefined>(undefined);
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
        <PlatformLayout
            title={t('Cards')}
            actions={
                canManage && tab === 'cards' ? (
                    <>
                        {canVoidCards && (
                            <>
                                {selection.length > 0 && (
                                    <Button
                                        variant="destructive"
                                        onClick={() => setVoidScope(selection)}
                                    >
                                        {t('Void selected cards ({{count}})', {
                                            count: selection.length,
                                        })}
                                    </Button>
                                )}
                                <Button variant="secondary" onClick={() => setVoidScope(null)}>
                                    {t('Card voiding tasks')}
                                </Button>
                            </>
                        )}
                        {selection.length > 0 && (
                            <Button
                                variant="secondary"
                                onClick={() => openSync({ cards: selection })}
                            >
                                {t('Sync selected cards ({{count}})', { count: selection.length })}
                            </Button>
                        )}
                        <Button onClick={() => setSyncOpen(true)}>
                            {t('Sync card transactions')}
                        </Button>
                    </>
                ) : undefined
            }
        >
            {voidScope !== undefined && (
                <CardVoidBatchDialog
                    cards={voidScope}
                    company={filters.company}
                    onClose={() => setVoidScope(undefined)}
                    onCompleted={() => {
                        setSelected({});
                        router.reload({ only: ['cards'] });
                    }}
                />
            )}
            <Head title={t('Card operations')} />
            <div className="space-y-4">
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
                        {issueError && (
                            <p role="alert" className="mb-3 text-sm text-destructive">
                                {issueError}
                            </p>
                        )}
                        <PlatformAccountTable
                            key={JSON.stringify(filters)}
                            page={orders}
                            companies={companies}
                            filters={filters}
                            url="/platform/cards"
                            extraQuery={{ tab: 'orders' }}
                            searchLabel={t('Search account ID, email or phone')}
                            columns={[
                                {
                                    label: 'Company / User',
                                    render: (row) => <RecordUserCell row={row} />,
                                },
                                { label: 'Product', render: (row) => row.productName },
                                {
                                    label: 'Actions',
                                    render: (row) =>
                                        canManage &&
                                        ['PENDING', 'PROCESSING', 'UNKNOWN'].includes(
                                            row.status,
                                        ) ? (
                                            <Button
                                                variant="secondary"
                                                size="sm"
                                                disabled={refreshingIssue !== null}
                                                onClick={() => {
                                                    setRefreshingIssue(row.id);
                                                    setIssueError(null);
                                                    router.post(
                                                        `/platform/tenants/${row.tenantId}/card-issues/${row.id}/sync`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                            onError: (errors) =>
                                                                setIssueError(
                                                                    Object.values(errors).join(' '),
                                                                ),
                                                            onFinish: () =>
                                                                setRefreshingIssue(null),
                                                        },
                                                    );
                                                }}
                                            >
                                                {t(
                                                    refreshingIssue === row.id
                                                        ? 'Refreshing…'
                                                        : 'Refresh status',
                                                )}
                                            </Button>
                                        ) : (
                                            '—'
                                        ),
                                },
                                {
                                    label: 'Amounts',
                                    render: (row) =>
                                        `${displayMoney(row.openingFee)} + ${displayMoney(row.initialLoadAmount)} ${adminAssetLabel(row.asset)}`,
                                },
                                {
                                    label: 'Status',
                                    render: (row) => (
                                        <StatusBadge
                                            status={platformCardStatusTone(row.status)}
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
                                {selection.length > 0 && (
                                    <div className="mb-3 flex flex-wrap items-center gap-2">
                                        <Button variant="ghost" onClick={() => setSelected({})}>
                                            {t('Clear selection')}
                                        </Button>
                                    </div>
                                )}
                                <Dialog
                                    open={syncOpen}
                                    onOpenChange={(open) => {
                                        setSyncOpen(open);
                                        if (!open) setSyncScope(null);
                                    }}
                                >
                                    <DialogContent
                                        presentation="drawer"
                                        className="max-w-5xl"
                                        closeLabel={t('Close')}
                                    >
                                        <DialogHeader>
                                            <DialogTitle>{t('Sync card transactions')}</DialogTitle>
                                            <DialogDescription>
                                                {t(
                                                    'Closing this dialog pauses sync. Reopen and select Continue sync to resume.',
                                                )}
                                            </DialogDescription>
                                        </DialogHeader>
                                        <Button
                                            className="mb-4"
                                            onClick={() => openSync({ company: filters.company })}
                                        >
                                            {t('Bulk sync card transactions')}
                                        </Button>
                                        {syncOpen && (
                                            <CardTransactionBatchSync
                                                companies={companies}
                                                scope={syncScope}
                                                onClose={() => setSyncScope(null)}
                                                onCompleted={completedSync}
                                            />
                                        )}
                                    </DialogContent>
                                </Dialog>
                            </>
                        )}
                        {refreshError && (
                            <OperationFeedback
                                role="alert"
                                className="mb-3 text-sm text-destructive"
                            >
                                {t(refreshError)}
                            </OperationFeedback>
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

                                ...platformCardColumns,
                                {
                                    label: 'Actions',
                                    render: (row) => (
                                        <CardRowActions
                                            card={row}
                                            onViewTransactions={() => setSelectedCard(row)}
                                            onSync={() =>
                                                openSync({ company: row.tenantId, cards: [row] })
                                            }
                                            onVoid={
                                                canVoidCards ? () => setVoidScope([row]) : undefined
                                            }
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
                                  openSync({
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
    onViewTransactions,
    onSync,
    onVoid,
}: {
    card: UserCard;
    canManage: boolean;
    refreshing: boolean;
    onRefresh: () => void;
    onViewTransactions: () => void;
    onSync: () => void;
    onVoid?: () => void;
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
                    <DropdownMenuItem onSelect={() => requestAnimationFrame(onViewTransactions)}>
                        {t('View transactions')}
                    </DropdownMenuItem>
                    {canManage && (
                        <DropdownMenuItem onSelect={() => requestAnimationFrame(onSync)}>
                            {t('Sync')}
                        </DropdownMenuItem>
                    )}
                    <DropdownMenuItem disabled={refreshing} onSelect={onRefresh}>
                        {t(refreshing ? 'Refreshing' : 'Refresh balance')}
                    </DropdownMenuItem>
                    {onVoid && (
                        <DropdownMenuItem
                            className="text-destructive"
                            onSelect={() => requestAnimationFrame(onVoid)}
                        >
                            {t('Void card')}
                        </DropdownMenuItem>
                    )}
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
