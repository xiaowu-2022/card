import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { exactAmount } from '@/lib/exact-amount';
import { t, errorMessage } from '@/i18n/admin';
import type { SharedProps } from '@/types/global';
import type { Order } from '@/pages/platform/AssetOrders';

export function DepositReceiptTypeSwitch({ order }: { order: Order }) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const [open, setOpen] = useState(false);
    const form = useForm({
        request_id: '',
        receipt_type: 'ACTUAL' as 'ACTUAL' | 'ADVANCE',
        revision: 0,
        confirmed: false,
    });
    if (
        !order.canChangeReceiptType ||
        !permissions.includes('wallet_topups.confirm') ||
        !permissions.includes('partners.manage') ||
        (order.receiptType !== 'ADVANCE' && !order.canAdvance)
    )
        return null;
    const target = order.receiptType === 'ADVANCE' ? 'ACTUAL' : 'ADVANCE';
    const actualLabel = order.manuallyConfirmed ? 'Manual confirmation' : 'Automatic receipt';
    const targetLabel =
        target === 'ADVANCE'
            ? 'Switch to advance amount'
            : order.manuallyConfirmed
              ? 'Switch to manual confirmation'
              : 'Switch to automatic receipt';
    return (
        <>
            <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                    form.setData({
                        request_id: crypto.randomUUID(),
                        receipt_type: target,
                        revision: order.receiptTypeRevision ?? 0,
                        confirmed: false,
                    });
                    form.clearErrors();
                    setOpen(true);
                }}
            >
                {t(targetLabel)}
            </Button>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (!form.processing) setOpen(next);
                }}
            >
                <DialogContent closeDisabled={form.processing} className="max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{t('Change receipt type')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Changing receipt type updates receipt totals and partner advances. The wallet balance and credited amount stay unchanged.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(
                                `/platform/tenants/${order.tenant_id}/deposit-orders/${order.source}/${order.id}/receipt-type`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => setOpen(false),
                                },
                            );
                        }}
                    >
                        <p className="break-all text-sm">
                            {t('Order')}: {order.id}
                        </p>
                        <p>
                            {t('Receipt type')}:{' '}
                            {t(
                                form.data.receipt_type === 'ADVANCE'
                                    ? actualLabel
                                    : 'Advance amount',
                            )}{' '}
                            →{' '}
                            {t(
                                form.data.receipt_type === 'ADVANCE'
                                    ? 'Advance amount'
                                    : actualLabel,
                            )}
                        </p>
                        <p className="tabular-nums">
                            {t('Actual received amount')}:{' '}
                            {exactAmount(order.actualReceivedAmount ?? order.amount)} {order.asset}
                        </p>
                        {Object.values(form.errors).length > 0 && (
                            <OperationFeedback>
                                {Object.values(form.errors)
                                    .map((error) => errorMessage(error))
                                    .join('\n')}
                            </OperationFeedback>
                        )}
                        <label className="flex items-start gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={form.data.confirmed}
                                onChange={(event) =>
                                    form.setData('confirmed', event.target.checked)
                                }
                            />
                            {t('I confirm the receipt type change.')}
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={form.processing}
                                onClick={() => setOpen(false)}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button disabled={form.processing || !form.data.confirmed}>
                                {t('Confirm and save')}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
