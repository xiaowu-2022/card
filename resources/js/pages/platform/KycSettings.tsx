import { Head, useForm } from '@inertiajs/react';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';

type KycPolicy = { enabled: boolean; maxAccountsPerIdentity: number; reviewMode: string };

export default function KycSettings({ policy }: { policy: KycPolicy }) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('Identity verification settings')} />
            <div className="space-y-6">
                <PageHeader title={t('Identity verification settings')} />
                <KycForm policy={policy} />
            </div>
        </PlatformLayout>
    );
}

function KycForm({ policy }: { policy: KycPolicy }) {
    useAdminTranslation();
    const form = useForm({
        enabled: policy.enabled,
        max_accounts_per_identity: policy.maxAccountsPerIdentity?.toString() ?? '1',
        review_mode: policy.reviewMode,
        automatic_approval_confirmed: false,
    });
    return (
        <>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>{t('KYC policy')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/platform/settings/kyc', {
                                preserveScroll: true,
                                onSuccess: () =>
                                    form.setData('automatic_approval_confirmed', false),
                            });
                        }}
                    >
                        <ToggleRow
                            label={t('KYC enabled')}
                            description={t(
                                'Controls whether active users can submit identity documents.',
                            )}
                            checked={form.data.enabled}
                            onChange={(value) => form.setData('enabled', value)}
                        />
                        <FormField
                            id="identity-limit"
                            label={t('Maximum accounts per identity')}
                            error={errorMessage(form.errors.max_accounts_per_identity)}
                        >
                            <Input
                                id="identity-limit"
                                type="number"
                                min={1}
                                max={100}
                                value={form.data.max_accounts_per_identity}
                                onChange={(event) =>
                                    form.setData('max_accounts_per_identity', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="review-mode"
                            label={t('Review mode')}
                            error={errorMessage(form.errors.review_mode)}
                        >
                            <Select
                                value={form.data.review_mode}
                                onValueChange={(value) => {
                                    form.setData('review_mode', value);
                                    form.setData('automatic_approval_confirmed', false);
                                }}
                            >
                                <SelectTrigger id="review-mode">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="MANUAL">{t('MANUAL')}</SelectItem>
                                    <SelectItem value="AUTOMATIC">
                                        {t('Automatic approval')}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </FormField>
                        {form.data.review_mode === 'AUTOMATIC' && (
                            <FormField
                                id="automatic-approval-confirmed"
                                label={t('Confirm automatic approval')}
                                error={errorMessage(form.errors.automatic_approval_confirmed)}
                            >
                                <label
                                    className="flex items-start gap-3 text-sm"
                                    htmlFor="automatic-approval-confirmed"
                                >
                                    <Checkbox
                                        id="automatic-approval-confirmed"
                                        checked={form.data.automatic_approval_confirmed}
                                        onCheckedChange={(value) =>
                                            form.setData(
                                                'automatic_approval_confirmed',
                                                value === true,
                                            )
                                        }
                                    />
                                    {t(
                                        'I confirm automatic approval for new valid submissions in all companies. Identity account limits remain enforced.',
                                    )}
                                </label>
                            </FormField>
                        )}
                        {form.errors.enabled && (
                            <p className="text-sm text-destructive">
                                {errorMessage(form.errors.enabled)}
                            </p>
                        )}
                        <Button
                            disabled={
                                form.processing ||
                                (form.data.review_mode === 'AUTOMATIC' &&
                                    !form.data.automatic_approval_confirmed)
                            }
                        >
                            {t('Save KYC configuration')}
                        </Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

function ToggleRow({
    label,
    description,
    checked,
    onChange,
}: {
    label: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    useAdminTranslation();
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
            <div>
                <p className="font-medium">{t(label)}</p>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch checked={checked} onCheckedChange={onChange} />
        </div>
    );
}
