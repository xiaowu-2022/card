import { useForm } from '@inertiajs/react';
import { t, errorMessage, useAdminTranslation } from '@/i18n/admin';
import { displayMoney } from '@/lib/exact-amount';
import { MoneyInput } from '@/components/shared/MoneyInput';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';

export function CompanyDepositSettings({
    companyId,
    amount,
    asset,
    waitDays,
    canManage,
}: {
    companyId: string;
    amount: string;
    asset: string;
    waitDays: number | null;
    canManage: boolean;
}) {
    useAdminTranslation();
    const form = useForm({
        required_security_deposit_amount: amount,
        security_deposit_refund_wait_days: waitDays === null ? '' : String(waitDays),
    });
    return (
        <Card>
            <CardHeader>
                <CardTitle>{t('Company security deposit settings')}</CardTitle>
            </CardHeader>
            <CardContent>
                {canManage ? (
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.put(`/platform/tenants/${companyId}/deposit-settings`, {
                                preserveScroll: true,
                            });
                        }}
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="company-deposit-amount"
                                label={`${t('Required security deposit')} (${asset})`}
                                error={errorMessage(form.errors.required_security_deposit_amount)}
                            >
                                <MoneyInput
                                    id="company-deposit-amount"
                                    value={form.data.required_security_deposit_amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'required_security_deposit_amount',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </FormField>
                            <FormField
                                id="company-refund-wait"
                                label={t('Deposit refund waiting period (days)')}
                                error={errorMessage(form.errors.security_deposit_refund_wait_days)}
                            >
                                <Input
                                    id="company-refund-wait"
                                    type="number"
                                    min="0"
                                    max="3650"
                                    step="1"
                                    value={form.data.security_deposit_refund_wait_days}
                                    onChange={(event) =>
                                        form.setData(
                                            'security_deposit_refund_wait_days',
                                            event.target.value,
                                        )
                                    }
                                    required
                                />
                            </FormField>
                        </div>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Changes apply to new refund requests only. Existing deposits and refund deadlines remain unchanged.',
                            )}
                        </p>
                        <Button disabled={form.processing}>{t('Save deposit settings')}</Button>
                    </form>
                ) : (
                    <dl className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Required security deposit')}
                            </dt>
                            <dd>
                                {displayMoney(amount)} {asset}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-sm text-muted-foreground">
                                {t('Deposit refund waiting period (days)')}
                            </dt>
                            <dd>{waitDays ?? t('Not configured')}</dd>
                        </div>
                    </dl>
                )}
            </CardContent>
        </Card>
    );
}
