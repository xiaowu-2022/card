import { useForm } from '@inertiajs/react';
import { t, errorMessage, useClientTranslation } from '@/i18n';
import { displayMoney, meetsTopupMinimum } from '@/lib/exact-amount';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';

export function DepositTopupForm({
    minimum,
    asset,
    enabled,
}: {
    minimum: string;
    asset: string;
    enabled: boolean;
}) {
    useClientTranslation();
    const form = useForm({
        request_id: crypto.randomUUID(),
        requested_amount: displayMoney(minimum),
    });
    const valid = meetsTopupMinimum(form.data.requested_amount, minimum);
    return (
        <form
            className="rounded-[var(--user-radius-lg)] border bg-surface p-5 sm:p-7"
            onSubmit={(event) => {
                event.preventDefault();
                if (enabled && valid && !form.processing) form.post('/security-deposit/top-ups');
            }}
        >
            <FormField id="deposit-topup-amount" label={t('Deposit top-up amount')}>
                <div className="flex items-center gap-3">
                    <Input
                        id="deposit-topup-amount"
                        inputMode="decimal"
                        autoComplete="off"
                        aria-describedby="deposit-topup-minimum"
                        disabled={form.processing}
                        value={form.data.requested_amount}
                        onChange={(event) => form.setData('requested_amount', event.target.value)}
                    />
                    <span className="text-sm font-semibold">{asset}</span>
                </div>
            </FormField>
            <p id="deposit-topup-minimum" className="mt-3 text-sm text-muted-foreground">
                {t('Minimum {{amount}} {{asset}}. You can increase this amount.', {
                    amount: displayMoney(minimum),
                    asset,
                })}
            </p>
            <p className="mt-3 text-xs leading-5 text-muted-foreground">
                {t(
                    'Only the required deposit is reserved. Any extra stays in your available balance.',
                )}
            </p>
            {enabled && (
                <p className="mt-3 text-xs leading-5 text-muted-foreground">
                    {t(
                        'A 0.01–0.99 identification amount will be added. Your wallet receives the full exact amount sent; it is not a fee.',
                    )}
                </p>
            )}
            {Object.values(form.errors).map((error, index) => (
                <p key={index} className="mt-3 text-sm text-destructive" role="alert">
                    {errorMessage(error)}
                </p>
            ))}
            <Button
                type="submit"
                className="mt-5 w-full"
                disabled={!enabled || !valid || form.processing}
            >
                {form.processing ? t('Creating instructions…') : t('Create payment instructions')}
            </Button>
        </form>
    );
}
