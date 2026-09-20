import { useState } from 'react';
import { useForm } from '@inertiajs/react';
import { t } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export function CardBalanceLimit({
    card,
}: {
    card: { id: string; tenantId: string; balanceLimit: string | null };
}) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        balance_limit: card.balanceLimit === null ? '' : card.balanceLimit.replace(/\.?0+$/, ''),
    });
    return (
        <>
            <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
                {t('Balance limit')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{t('Balance limit')}</DialogTitle>
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.put(
                                `/platform/tenants/${card.tenantId}/cards/${card.id}/balance-limit`,
                                { preserveScroll: true, onSuccess: () => setOpen(false) },
                            );
                        }}
                    >
                        <label className="block space-y-2">
                            <span>{t('Maximum card balance (USD)')}</span>
                            <Input
                                inputMode="decimal"
                                value={form.data.balance_limit}
                                onChange={(e) => form.setData('balance_limit', e.target.value)}
                            />
                        </label>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Leave empty to use the product limit. Excess reload funds remain in the wallet. Lowering the limit does not withdraw existing card funds.',
                            )}
                        </p>
                        {form.errors.balance_limit && (
                            <p role="alert" className="text-sm text-destructive">
                                {t(form.errors.balance_limit)}
                            </p>
                        )}
                        <Button disabled={form.processing}>{t('Save')}</Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
