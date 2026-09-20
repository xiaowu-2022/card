import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t } from '@/i18n/admin';
import { displayMoney } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export function CardOverflowSpend({
    card,
}: {
    card: { id: string; tenantId: string; overflowBalance: string };
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        amount: '',
        note: '',
        request_id: crypto.randomUUID(),
        confirmed: false,
    });
    return (
        <>
            <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
                {t('Record overflow consumption')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent closeLabel={t('Close')}>
                    <DialogHeader>
                        <DialogTitle>{t('Record overflow consumption')}</DialogTitle>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                `/platform/tenants/${card.tenantId}/cards/${card.id}/overflow-spends`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setOpen(false);
                                        form.reset();
                                        form.setData('request_id', crypto.randomUUID());
                                    },
                                },
                            );
                        }}
                    >
                        <p>
                            {t('Overflow balance')}: {displayMoney(card.overflowBalance)} USD
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Record only completed external consumption after the provider balance is exhausted. This deducts the overflow balance immediately.',
                            )}
                        </p>
                        <label className="block space-y-2">
                            <span>{t('Amount (USD)')}</span>
                            <Input
                                required
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                        </label>
                        <label className="block space-y-2">
                            <span>{t('Consumption reference or note')}</span>
                            <Input
                                required
                                maxLength={500}
                                value={form.data.note}
                                onChange={(e) => form.setData('note', e.target.value)}
                            />
                        </label>
                        <label className="flex items-center gap-2">
                            <input
                                type="checkbox"
                                checked={form.data.confirmed}
                                onChange={(e) => form.setData('confirmed', e.target.checked)}
                            />
                            {t('I confirm this consumption has occurred.')}
                        </label>
                        {Object.values(form.errors).map((error) => (
                            <p key={error} role="alert" className="text-sm text-destructive">
                                {t(error)}
                            </p>
                        ))}
                        <Button disabled={form.processing || !form.data.confirmed}>
                            {t('Confirm')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
