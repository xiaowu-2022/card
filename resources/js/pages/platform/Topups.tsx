import { Head, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';
import { t, useAdminTranslation, errorMessage, dateTime } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
    manuallyConfirmed: boolean;
    manualConfirmedAt: string | null;
    manualConfirmedBy: { id: string; name: string | null } | null;
};
type Props = {
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string; status?: string };
    orders: AccountPage<Order>;
};

function ConfirmForm({
    companyId,
    order,
    close,
}: {
    companyId: string;
    order: Order;
    close: () => void;
}) {
    const form = useForm({ request_id: crypto.randomUUID(), confirmed: false });
    return (
        <form
            className="space-y-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/platform/tenants/${companyId}/topups/${order.id}/confirm`, {
                    onSuccess: close,
                });
            }}
        >
            <p>
                {order.companyName} · {order.accountId} · {t('Order')}: {order.reference} ·{' '}
                <MoneyDisplay amount={order.amount} asset={order.asset} compact />
            </p>
            <label className="flex items-start gap-3 text-sm">
                <Checkbox
                    checked={form.data.confirmed}
                    disabled={form.processing}
                    onCheckedChange={(checked) => form.setData('confirmed', checked === true)}
                />
                <span>
                    {t(
                        'I confirm receipt of the full order amount and authorize crediting this user wallet without an on-chain check.',
                    )}
                </span>
            </label>
            {Object.values(form.errors).map((error, index) => (
                <p key={index} role="alert" className="text-sm text-destructive">
                    {errorMessage(error)}
                </p>
            ))}
            <Button type="submit" disabled={form.processing || !form.data.confirmed}>
                {form.processing ? t('Confirming…') : t('Confirm and credit')}
            </Button>
        </form>
    );
}

export default function Topups({ companies, filters, orders }: Props) {
    useAdminTranslation();
    const canConfirm =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('wallet_topups.confirm');
    const [selected, setSelected] = useState<Order | null>(null);
    return (
        <PlatformLayout>
            <Head title={t('Payment orders')} />
            <PageHeader title={t('Payment orders')} eyebrow={t('Operations')} />
            <p className="my-4 text-sm text-muted-foreground">
                {t(
                    'SaaS administrators can confirm receipt manually. The full order amount is credited once; company administrators have read-only access.',
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
                    { label: 'Tenant', render: (order) => order.companyName },
                    {
                        label: 'User',
                        render: (order) => (
                            <div>
                                {order.accountId}
                                <p className="text-xs text-muted-foreground">{order.userEmail}</p>
                            </div>
                        ),
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
                                        {t('Manually confirmed')}
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
                        render: (order) =>
                            order.manuallyConfirmed ? (
                                <div>
                                    {order.manualConfirmedBy?.name ?? t('Unknown operator')}
                                    <p className="max-w-48 break-all text-xs text-muted-foreground">
                                        {order.manualConfirmedBy?.id}
                                    </p>
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
                                'This credits the full order amount immediately without an on-chain check. Confirm only after you have verified receipt yourself. This action cannot be undone here.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    {selected && (
                        <ConfirmForm
                            key={selected.id}
                            companyId={selected.companyId}
                            order={selected}
                            close={() => setSelected(null)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </PlatformLayout>
    );
}
