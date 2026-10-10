import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t, errorMessage } from '@/i18n/admin';
import { exactAmount, receiptDifference } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { ReceiptTypeSelect } from './ReceiptTypeSelect';

export function ManualReceiptForm({
    amount,
    asset,
    canAdvance,
    url,
    onSuccess,
}: {
    amount: string;
    asset: string;
    canAdvance: boolean;
    url: string;
    onSuccess: () => void;
}) {
    const [review, setReview] = useState(false);
    const form = useForm({
        request_id: crypto.randomUUID(),
        actual_received_amount: '',
        receipt_type: 'ACTUAL' as 'ACTUAL' | 'ADVANCE',
        confirmed: true,
    });
    const difference = receiptDifference(form.data.actual_received_amount, amount, asset);
    return (
        <div className="space-y-3">
            <label className="block space-y-2 text-sm">
                <span>
                    {t('Actual received amount')} ({asset})
                </span>
                <Input
                    value={form.data.actual_received_amount}
                    inputMode="decimal"
                    disabled={form.processing}
                    onChange={(e) => form.setData('actual_received_amount', e.target.value)}
                />
            </label>
            <ReceiptTypeSelect
                value={form.data.receipt_type}
                canAdvance={canAdvance}
                disabled={form.processing}
                onChange={(value) => form.setData('receipt_type', value)}
            />
            <Button
                type="button"
                disabled={difference === null || form.processing}
                onClick={() => setReview(true)}
            >
                {t('Check amount')}
            </Button>
            <Dialog
                open={review}
                onOpenChange={(open) => {
                    if (!form.processing) setReview(open);
                }}
            >
                <DialogContent closeLabel={t('Close')}>
                    <DialogHeader>
                        <DialogTitle>{t('Check amount')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Verify the actual receipt before crediting. The actual received amount will be credited once.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-2 gap-3 text-sm">
                        <dt>{t('Order amount')}</dt>
                        <dd>
                            {exactAmount(amount)} {asset}
                        </dd>
                        <dt>{t('Actual received amount')}</dt>
                        <dd>
                            {exactAmount(form.data.actual_received_amount)} {asset}
                        </dd>
                        <dt>{t('Difference (actual minus order)')}</dt>
                        <dd>
                            {difference} {asset}
                        </dd>
                        <dt>{t('Receipt type')}</dt>
                        <dd>
                            {t(
                                form.data.receipt_type === 'ADVANCE'
                                    ? 'Advance amount'
                                    : 'Actual receipt',
                            )}
                        </dd>
                    </dl>
                    {Object.values(form.errors).map((error, i) => (
                        <OperationFeedback
                            key={i}
                            role="alert"
                            className="text-sm text-destructive"
                        >
                            {errorMessage(error)}
                        </OperationFeedback>
                    ))}
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => setReview(false)}
                        >
                            {t('Return to edit')}
                        </Button>
                        <Button
                            type="button"
                            disabled={form.processing || difference === null}
                            onClick={() =>
                                form.post(url, {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setReview(false);
                                        onSuccess();
                                    },
                                })
                            }
                        >
                            {t('Confirm receipt')}
                        </Button>
                    </div>
                </DialogContent>
            </Dialog>
        </div>
    );
}
