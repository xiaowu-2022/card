import { ReceiptTypeSelect } from '@/components/admin/ReceiptTypeSelect';
import { WithdrawalExport } from '@/components/admin/WithdrawalExport';
import { DetailDrawerContent } from '@/components/admin/DetailDrawer';
import { AssetNavigation } from '@/components/admin/AssetNavigation';
import type { SharedProps } from '@/types/global';
import {
    ManualOperationHistory,
    type ManualOperation,
} from '@/components/admin/ManualOperationHistory';
import { useState } from 'react';
import { Head, useForm, usePage } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { exactAmount } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { t, useAdminTranslation, errorMessage, dateTime } from '@/i18n/admin';

type Order = {
    receiptType: 'ACTUAL' | 'ADVANCE';
    canAdvance: boolean;
    operations?: ManualOperation[];
    legacy: boolean;
    canConfirm: boolean;
    canRecheck: boolean;
    manuallyConfirmed: boolean;
    source: string;
    id: string;
    reference: string;
    tenant_id: string;
    company: string;
    accountId: string;
    userEmail: string;
    asset: string;
    network: string | null;
    amount: string;
    status: string;
    created_at: string;
    arrival_at: string | null;
    operator: string | null;
    operated_at: string | null;
    fee: string | null;
    address: string | null;
    tx_hash: string;
};
type Props = {
    mode: 'deposit' | 'withdrawal';
    orders: AccountPage<Order>;
    companies: { id: string; name: string }[];
    filters: Record<string, string | undefined>;
    statuses: string[];
    observations: {
        network: string;
        event_id: string;
        rail_code: string;
        amount: string;
        occurred_at: string;
    }[];
};
export default function AssetOrders({
    mode,
    orders,
    observations,
    companies,
    filters,
    statuses,
}: Props) {
    useAdminTranslation();
    const title = mode === 'deposit' ? 'Deposit orders' : 'Withdrawal orders';
    const [selected, setSelected] = useState<string | null>(null);
    // Resolve from refreshed props after mutations so the dialog cannot retain stale status/actions.
    const order = orders.data.find((item) => item.source + ':' + item.id === selected);
    return (
        <PlatformLayout
            title={t(title)}
            actions={
                mode === 'withdrawal' ? (
                    <WithdrawalExport filters={filters} total={orders.total} />
                ) : undefined
            }
            description={t(
                mode === 'deposit'
                    ? 'Review deposits and confirm received funds.'
                    : 'Review withdrawal requests and track payout progress.',
            )}
        >
            <Head title={t(title)} />
            <div className="space-y-4">
                <AssetNavigation active={mode} />
                <PlatformAccountTable
                    key={mode + JSON.stringify(filters)}
                    page={orders}
                    rowKey={(o) => o.source + ':' + o.id}
                    companies={companies}
                    filters={filters}
                    statuses={statuses}
                    url={mode === 'deposit' ? '/platform/topups' : '/platform/asset-withdrawals'}
                    searchLabel={t('Search account ID, email or order')}
                    selectFilters={[
                        {
                            key: 'asset',
                            label: 'Asset',
                            allLabel: 'All assets',
                            values: ['USDT', 'USDC', 'ETH', 'BTC', 'USD'],
                        },
                        {
                            key: 'network',
                            label: 'Network',
                            allLabel: 'All networks',
                            values: ['TRON', 'ETHEREUM', 'BITCOIN'],
                        },
                    ]}
                    columns={[
                        { label: 'Tenant', render: (o) => o.company },
                        {
                            label: 'User',
                            className: 'w-56 max-w-56',
                            render: (o) => (
                                <div className="w-48 max-w-48">
                                    <p className="truncate" title={o.accountId}>
                                        {o.accountId}
                                    </p>
                                    <p
                                        className="truncate text-xs text-muted-foreground"
                                        title={o.userEmail}
                                    >
                                        {o.userEmail}
                                    </p>
                                </div>
                            ),
                        },
                        { label: 'Order', render: (o) => <span title={o.id}>{o.reference}</span> },
                        {
                            label: 'Exact amount',
                            render: (o) => (
                                <span className="tabular-nums">
                                    {exactAmount(o.amount)} {o.asset}
                                </span>
                            ),
                        },
                        { label: 'Network', render: (o) => o.network || '—' },
                        ...(mode === 'withdrawal'
                            ? [
                                  {
                                      label: 'Fee',
                                      render: (o: Order) =>
                                          o.fee === null ? (
                                              '—'
                                          ) : (
                                              <span className="tabular-nums">
                                                  {exactAmount(o.fee)} {o.asset}
                                              </span>
                                          ),
                                  },
                              ]
                            : []),
                        {
                            label: 'Status',
                            render: (o) => (
                                <div>
                                    {t(o.status)}
                                    {mode === 'deposit' && o.manuallyConfirmed && (
                                        <p className="text-xs text-muted-foreground">
                                            {t('Manually confirmed')}
                                        </p>
                                    )}
                                </div>
                            ),
                        },
                        {
                            label: 'Created',
                            render: (o) => (
                                <span className="whitespace-pre">
                                    {dateTime(o.created_at).replace(' ', '\n')}
                                </span>
                            ),
                        },
                        {
                            label: mode === 'deposit' ? 'Arrival time' : 'Completed at',
                            render: (o) => (
                                <span className="whitespace-pre">
                                    {o.arrival_at ? dateTime(o.arrival_at).replace(' ', '\n') : '—'}
                                </span>
                            ),
                        },
                        {
                            label: 'Operator',
                            className: 'w-40 max-w-40',
                            render: (o) => (
                                <div className="w-32 truncate" title={o.operator ?? undefined}>
                                    {o.operator || '—'}
                                </div>
                            ),
                        },
                        {
                            label: 'Operation time',
                            render: (o) => (
                                <span className="whitespace-pre">
                                    {o.operated_at
                                        ? dateTime(o.operated_at).replace(' ', '\n')
                                        : '—'}
                                </span>
                            ),
                        },
                        {
                            label: 'Actions',
                            className: 'sticky right-0 bg-surface',
                            render: (o) => (
                                <Button
                                    variant="secondary"
                                    onClick={() => setSelected(o.source + ':' + o.id)}
                                >
                                    {t('View details')}
                                </Button>
                            ),
                        },
                    ]}
                />
                {observations.length > 0 && (
                    <section className="rounded-xl border p-4">
                        <h2 className="font-semibold">{t('Transfers requiring review')}</h2>
                        {observations.map((o) => (
                            <div
                                className="space-y-1 break-all border-b py-3 text-sm"
                                key={o.event_id}
                            >
                                <p>
                                    {o.network} · {o.rail_code}
                                </p>
                                <p>{o.event_id}</p>
                                <p>
                                    {exactAmount(o.amount)} · {dateTime(o.occurred_at)}
                                </p>
                            </div>
                        ))}
                    </section>
                )}
            </div>
            <Dialog
                open={!!order}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <DetailDrawerContent closeLabel={t('Close')} className="w-[min(92vw,48rem)]">
                    <DialogHeader className="shrink-0 pr-10">
                        <DialogTitle>{t('Order details')}</DialogTitle>
                        <DialogDescription>{order?.reference}</DialogDescription>
                    </DialogHeader>
                    <div
                        className="min-h-0 flex-1 overflow-y-auto overscroll-contain"
                        data-detail-body
                    >
                        {order && <OrderRow key={selected} order={order} mode={mode} />}
                    </div>
                </DetailDrawerContent>
            </Dialog>
        </PlatformLayout>
    );
}
function OrderRow({ order: o, mode }: { order: Order; mode: string }) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canReview = permissions.includes('withdrawals.review');
    const form = useForm({
        request_id: crypto.randomUUID(),
        tx_hash: o.tx_hash,
        confirmed: false,
        receipt_type: 'ACTUAL' as 'ACTUAL' | 'ADVANCE',
        approve: true,
        reason: '',
    });
    const [action, setAction] = useState('');
    const [password, setPassword] = useState('');
    const [address, setAddress] = useState<string | null>(null);
    const [revealError, setRevealError] = useState(false);
    const post = () =>
        form.post(
            `/platform/tenants/${o.tenant_id}/${o.legacy ? (mode === 'deposit' ? 'topups' : 'asset-tron-withdrawals') : 'asset-orders'}/${o.id}/${o.legacy && mode === 'deposit' && action === 'recheck' ? 'verify' : action}`,
            {
                preserveScroll: true,
                onSuccess: () => {
                    setAction('');
                    form.reset('confirmed');
                },
            },
        );
    async function reveal() {
        setRevealError(false);
        try {
            const csrf = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '',
            );
            const response = await fetch(
                `/platform/tenants/${o.tenant_id}/${o.legacy ? 'asset-tron-withdrawals' : 'asset-orders'}/${o.id}/reveal`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Content-Type': 'application/json',
                        Accept: 'application/json',
                        'X-XSRF-TOKEN': csrf,
                    },
                    body: JSON.stringify({ password }),
                },
            );
            if (!response.ok) throw Error();
            const data: unknown = await response.json();
            if (
                !data ||
                typeof data !== 'object' ||
                !('address' in data) ||
                typeof data.address !== 'string'
            )
                throw Error();
            setAddress(data.address);
            setPassword('');
            setTimeout(() => setAddress(null), 30000);
        } catch {
            setRevealError(true);
            setPassword('');
        }
    }
    return (
        <section className="space-y-4 rounded-xl border bg-surface p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 className="font-semibold">
                        {o.company} · {o.asset}
                        {o.network ? ` · ${o.network}` : ''}
                    </h2>
                    <p className="mt-1 break-all text-xs text-muted-foreground">{o.id}</p>
                    <p className="mt-2 break-all text-sm">
                        {o.accountId} · {o.userEmail}
                    </p>
                    <p className="mt-1 text-xs text-muted-foreground">
                        {dateTime(o.created_at)} · {t(o.status)}
                    </p>
                </div>
                <p className="break-all text-xl font-semibold">
                    {exactAmount(o.amount)} {o.asset}
                </p>
            </div>
            <p className="break-all text-sm">{o.address || '—'}</p>
            {o.arrival_at && (
                <p className="text-sm">
                    {t(mode === 'deposit' ? 'Arrival time' : 'Completed at')}:{' '}
                    {dateTime(o.arrival_at)}
                </p>
            )}
            {o.tx_hash && (
                <p className="break-all text-sm">
                    {t('Transaction hash')}: {o.tx_hash}
                </p>
            )}
            {mode === 'deposit' && o.manuallyConfirmed && (
                <p>
                    {t('Receipt type')}:{' '}
                    {t(o.receiptType === 'ADVANCE' ? 'Advance amount' : 'Actual receipt')}
                </p>
            )}
            {o.operator && o.operated_at && (
                <p className="text-xs text-muted-foreground">
                    {o.operator} · {dateTime(o.operated_at)}
                </p>
            )}
            {o.fee !== null && (
                <p className="text-sm">
                    {t('Fee')}: {exactAmount(o.fee)} {o.asset}
                </p>
            )}
            {mode === 'deposit' && (
                <div className="flex flex-wrap gap-2">
                    {o.canRecheck && permissions.includes('wallet_topups.verify') && (
                        <Button variant="secondary" onClick={() => setAction('recheck')}>
                            {t('Recheck transfer')}
                        </Button>
                    )}
                    {o.canConfirm && permissions.includes('wallet_topups.confirm') && (
                        <Button variant="secondary" onClick={() => setAction('confirm')}>
                            {t('Manual receipt confirmation')}
                        </Button>
                    )}
                </div>
            )}
            {canReview && mode === 'withdrawal' && o.status === 'PENDING' && (
                <div className="flex gap-2">
                    <Button
                        onClick={() => {
                            form.setData('approve', true);
                            setAction('review');
                        }}
                    >
                        {t('Approve')}
                    </Button>
                    <Button
                        variant="secondary"
                        onClick={() => {
                            form.setData('approve', false);
                            setAction('review');
                        }}
                    >
                        {t('Reject')}
                    </Button>
                </div>
            )}
            {canReview &&
                mode === 'withdrawal' &&
                ['APPROVED', 'PROCESSING', 'VERIFYING', 'UNKNOWN'].includes(o.status) && (
                    <>
                        <div className="flex flex-wrap gap-2">
                            <Input
                                type="password"
                                className="max-w-xs"
                                value={password}
                                placeholder={t('Current password')}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                            <Button
                                variant="secondary"
                                onClick={() => {
                                    void reveal();
                                }}
                            >
                                {t('Reveal destination')}
                            </Button>
                            <Button onClick={() => setAction('verify')}>
                                {t('Verify payout')}
                            </Button>
                        </div>
                        {address && (
                            <p className="break-all rounded-lg bg-muted p-3 font-mono text-sm">
                                {address}
                            </p>
                        )}
                        {revealError && (
                            <p className="text-destructive">
                                {t('Unable to complete this request.')}
                            </p>
                        )}
                    </>
                )}
            {action && (
                <div className="space-y-3 rounded-xl bg-muted p-4">
                    <p className="text-sm">
                        {t(
                            action === 'confirm'
                                ? 'Confirm receipt of this exact asset and amount. This action credits the account and cannot be undone.'
                                : 'Review the order details before confirming. Unverified payouts remain on hold.',
                        )}
                    </p>
                    {['verify', 'recheck'].includes(action) && (
                        <Input
                            value={form.data.tx_hash}
                            placeholder={t('Transaction hash')}
                            onChange={(e) => form.setData('tx_hash', e.target.value)}
                        />
                    )}
                    {o.legacy && action === 'review' && !form.data.approve && (
                        <Input
                            value={form.data.reason}
                            placeholder={t('Review reason')}
                            onChange={(e) => form.setData('reason', e.target.value)}
                        />
                    )}

                    {action === 'confirm' && (
                        <ReceiptTypeSelect
                            value={form.data.receipt_type}
                            canAdvance={o.canAdvance && permissions.includes('partners.manage')}
                            disabled={form.processing}
                            onChange={(value) => {
                                form.setData('receipt_type', value);
                                form.setData('confirmed', false);
                            }}
                        />
                    )}
                    <label className="flex min-h-11 items-center gap-3 text-sm">
                        <input
                            type="checkbox"
                            checked={form.data.confirmed}
                            onChange={(e) => form.setData('confirmed', e.target.checked)}
                        />
                        {t('I confirm the order details.')}
                    </label>
                    <Button disabled={!form.data.confirmed || form.processing} onClick={post}>
                        {t('Confirm')}
                    </Button>
                </div>
            )}
            {!!o.operations?.length && <ManualOperationHistory operations={o.operations} />}
            {Object.values(form.errors).map((m, i) => (
                <p key={i} role="alert" className="text-sm text-destructive">
                    {errorMessage(m)}
                </p>
            ))}
        </section>
    );
}
