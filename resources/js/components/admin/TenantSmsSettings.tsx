import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useForm } from '@inertiajs/react';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

export type SmsSettings = {
    enabled: boolean;
    credentialsConfigured: boolean;
    signName: string;
    verificationTemplateCode: string;
    existingAccountTemplateCode: string;
    resendIntervalSeconds: number;
    codeTtlSeconds: number;
};

export function TenantSmsSettings({
    settings,
    actionUrl,
    name,
    onSaved,
    onProcessingChange,
}: {
    settings: SmsSettings;
    actionUrl: string;
    name: string;
    onSaved: () => void;
    onProcessingChange: (processing: boolean) => void;
}) {
    useAdminTranslation();
    // No remember key: credentials must not enter Inertia history or browser storage.
    const form = useForm({
        name,
        enabled: settings.enabled,
        access_key_id: '',
        access_key_secret: '',
        sign_name: settings.signName,
        verification_template_code: settings.verificationTemplateCode,
        existing_account_template_code: settings.existingAccountTemplateCode,
        resend_interval_seconds: String(settings.resendIntervalSeconds),
        code_ttl_seconds: String(settings.codeTtlSeconds),
        current_password: '',
    });
    const formError = (form.errors as Record<string, string>).form;
    return (
        <Card className="max-w-4xl">
            <CardHeader>
                <CardTitle>{t('Aliyun SMS')}</CardTitle>
            </CardHeader>
            <CardContent>
                <ConfigurationForm
                    className="space-y-6"
                    autoComplete="off"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(actionUrl, {
                            preserveScroll: true,
                            onStart: () => onProcessingChange(true),
                            onSuccess: onSaved,
                            onFinish: () => {
                                onProcessingChange(false);
                                form.setData((data) => ({
                                    ...data,
                                    access_key_id: '',
                                    access_key_secret: '',
                                    current_password: '',
                                }));
                            },
                        });
                    }}
                >
                    <FormField
                        id="profile-name"
                        label={t('Configuration name')}
                        error={errorMessage(form.errors.name)}
                    >
                        <Input
                            id="profile-name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            maxLength={100}
                            required
                        />
                    </FormField>
                    {formError && (
                        <Alert className="border-red-200 bg-red-50 text-red-800">
                            <AlertDescription>{errorMessage(formError)}</AlertDescription>
                        </Alert>
                    )}
                    <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
                        <div>
                            <label htmlFor="sms-enabled" className="font-medium">
                                {t('Enable configuration')}
                            </label>
                        </div>
                        <Switch
                            id="sms-enabled"
                            checked={form.data.enabled}
                            disabled={form.processing}
                            onCheckedChange={(value) => form.setData('enabled', value)}
                        />
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {settings.credentialsConfigured
                            ? t(
                                  'Credentials are configured. Leave both AccessKey fields blank to keep them, or replace both together.',
                              )
                            : t(
                                  'No credentials configured. Enter both AccessKey fields to enable SMS.',
                              )}
                    </p>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField
                            id="sms-key-id"
                            label={t('AccessKey ID')}
                            error={errorMessage(form.errors.access_key_id)}
                        >
                            <Input
                                id="sms-key-id"
                                type="password"
                                autoComplete="new-password"
                                maxLength={128}
                                value={form.data.access_key_id}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('access_key_id', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="sms-key-secret"
                            label={t('AccessKey Secret')}
                            error={errorMessage(form.errors.access_key_secret)}
                        >
                            <Input
                                id="sms-key-secret"
                                type="password"
                                autoComplete="new-password"
                                maxLength={256}
                                value={form.data.access_key_secret}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('access_key_secret', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="sms-sign"
                            label={t('SMS signature')}
                            error={errorMessage(form.errors.sign_name)}
                        >
                            <Input
                                id="sms-sign"
                                required={form.data.enabled}
                                maxLength={100}
                                value={form.data.sign_name}
                                disabled={form.processing}
                                onChange={(event) => form.setData('sign_name', event.target.value)}
                            />
                        </FormField>
                        <FormField
                            id="sms-template"
                            label={t('Verification template')}
                            description={t(
                                'Use an approved SMS_ template with the ${code} variable.',
                            )}
                            error={errorMessage(form.errors.verification_template_code)}
                        >
                            <Input
                                id="sms-template"
                                required={form.data.enabled}
                                pattern="SMS_[0-9]+"
                                maxLength={100}
                                value={form.data.verification_template_code}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('verification_template_code', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="sms-interval"
                            label={t('Send interval (seconds)')}
                            error={errorMessage(form.errors.resend_interval_seconds)}
                        >
                            <Input
                                id="sms-interval"
                                type="number"
                                min={60}
                                max={3600}
                                step={1}
                                required
                                value={form.data.resend_interval_seconds}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('resend_interval_seconds', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="sms-ttl"
                            label={t('Code validity (seconds)')}
                            error={errorMessage(form.errors.code_ttl_seconds)}
                        >
                            <Input
                                id="sms-ttl"
                                type="number"
                                min={Math.max(60, Number(form.data.resend_interval_seconds) || 60)}
                                max={3600}
                                step={1}
                                required
                                value={form.data.code_ttl_seconds}
                                disabled={form.processing}
                                onChange={(event) =>
                                    form.setData('code_ttl_seconds', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <details className="rounded-lg border p-4">
                        <summary className="cursor-pointer text-sm font-medium">
                            {t('Existing-account notice (optional)')}
                        </summary>
                        <div className="pt-4">
                            <FormField
                                id="sms-existing-template"
                                label={t('Notification template')}
                                description={t(
                                    'Optional approved template without variables. If empty, existing accounts receive no SMS; the registration response stays generic.',
                                )}
                                error={errorMessage(form.errors.existing_account_template_code)}
                            >
                                <Input
                                    id="sms-existing-template"
                                    pattern="SMS_[0-9]+"
                                    maxLength={100}
                                    value={form.data.existing_account_template_code}
                                    disabled={form.processing}
                                    onChange={(event) =>
                                        form.setData(
                                            'existing_account_template_code',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                        </div>
                    </details>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Use an Aliyun RAM key with SendSms permission. International sending requires the corresponding Aliyun service and approved templates.',
                        )}
                    </p>
                    <FormField
                        id="sms-password"
                        label={t('Current admin password')}
                        description={t(
                            'Confirm your password to save or disable SMS. Stored keys are never displayed.',
                        )}
                        error={errorMessage(form.errors.current_password)}
                    >
                        <Input
                            id="sms-password"
                            className="max-w-md"
                            type="password"
                            autoComplete="current-password"
                            required
                            maxLength={255}
                            value={form.data.current_password}
                            disabled={form.processing}
                            onChange={(event) =>
                                form.setData('current_password', event.target.value)
                            }
                        />
                    </FormField>
                    <Button type="submit" disabled={form.processing}>
                        {t('Save SMS settings')}
                    </Button>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}
