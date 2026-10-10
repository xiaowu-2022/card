import { RecordUserCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { ManualReceiptForm } from '@/components/admin/ManualReceiptForm';
import { Head, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { t, useAdminTranslation, dateTime } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { MoneyDisplay } from '@/components/admin/MoneyDisplay';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import type { SharedProps } from '@/types/global';

type Order = {
    userInfo?: UserInfo;
    userId?: string;
    id: string;
    companyId: string;
    companyName: string;
    accountId: string;
    userEmail: string;
    createdAt: string;
    creditedAt: string | null;
    reference: string;
    amount: string;
    asset: string;
    status: string;
    network: string | null;
    receiptType: 'ACTUAL' | 'ADVANCE';
    canAdvance: boolean;
    manuallyConfirmed: boolean;
    manualConfirmedAt: string | null;
    manualConfirmedBy: { id: string; name: string | null } | null;
};
type Props = {
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; status?: string };
    orders: AccountPage<Order>;
};

export default function Topups({ companies, filters, orders }: Props) {
    useAdminTranslation();
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canConfirm = permissions.includes('wallet_topups.confirm');
    const [selected, setSelected] = useState<Order | null>(null);
    return (
        <PlatformLayout title={t('Payment orders')}>
            <Head title={t('Payment orders')} />

            <p className="my-4 text-sm text-muted-foreground">
                {t(
                    'Verify the actual receipt before crediting. The actual received amount will be credited once.',
                )}
            </p>
            <PlatformAccountTable
                key={JSON.stringify(filters)}
                page={orders}
                companies={companies}
                filters={filters}
                url="/platform/topups"
                searchLabel={t('Search account ID, email or order')}
                statuses={[
                    'PENDING',
                    'PROCESSING',
                    'UNKNOWN',
                    'PAID',
                    'CREDITED',
                    'FAILED',
                    'CANCELLED',
                    'EXPIRED',
                    'REFUNDED',
                    'REQUIRES_REVIEW',
                ]}
                columns={[
                    {
                        label: 'Company / User',
                        className: 'w-52 max-w-52',
                        render: (order) => <RecordUserCell row={order} />,
                    },
                    { label: 'Order', render: (order) => order.reference },
                    {
                        label: 'Exact amount',
                        render: (order) => (
                            <MoneyDisplay amount={order.amount} asset={order.asset} compact />
                        ),
                    },
                    {
                        label: 'Status',
                        render: (order) => (
                            <div>
                                {t(order.status)}
                                {order.manuallyConfirmed && (
                                    <p className="text-xs text-muted-foreground">
                                        {t('Manually confirmed')} ·{' '}
                                        {t(
                                            order.receiptType === 'ADVANCE'
                                                ? 'Advance amount'
                                                : 'Actual receipt',
                                        )}
                                    </p>
                                )}
                            </div>
                        ),
                    },
                    { label: 'Created', render: (order) => dateTime(order.createdAt) },
                    {
                        label: 'Arrival time',
                        render: (order) => (order.creditedAt ? dateTime(order.creditedAt) : '—'),
                    },
                    {
                        label: 'Operator',
                        className: 'w-40 max-w-40',
                        render: (order) =>
                            order.manuallyConfirmed ? (
                                <div
                                    className="w-32 truncate"
                                    title={order.manualConfirmedBy?.name ?? t('Unknown operator')}
                                >
                                    {order.manualConfirmedBy?.name ?? t('Unknown operator')}
                                </div>
                            ) : (
                                '—'
                            ),
                    },
                    {
                        label: 'Operation time',
                        render: (order) =>
                            order.manualConfirmedAt ? dateTime(order.manualConfirmedAt) : '—',
                    },
                    {
                        label: 'Actions',
                        render: (order) =>
                            canConfirm &&
                            order.network === 'TRON' &&
                            ['PENDING', 'PROCESSING', 'UNKNOWN'].includes(order.status) ? (
                                <Button variant="secondary" onClick={() => setSelected(order)}>
                                    {t('Confirm receipt')}
                                </Button>
                            ) : (
                                '—'
                            ),
                    },
                ]}
            />
            <Dialog
                open={selected !== null}
                onOpenChange={(open) => {
                    if (!open) setSelected(null);
                }}
            >
                <DialogContent closeLabel={t('Close')}>
                    <DialogHeader>
                        <DialogTitle>{t('Confirm receipt')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Verify the actual receipt before crediting. The actual received amount will be credited once.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {selected && (
                        <ManualReceiptForm
                            key={selected.id}
                            amount={selected.amount}
                            asset={selected.asset}
                            canAdvance={
                                selected.canAdvance && permissions.includes('partners.manage')
                            }
                            url={`/platform/tenants/${selected.companyId}/topups/${selected.id}/confirm`}
                            onSuccess={() => setSelected(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
