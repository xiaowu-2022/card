import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useForm } from '@inertiajs/react';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';

export type EmailSettings = {
    enabled: boolean;
    tokenConfigured: boolean;
    fromAddress: string;
    fromName: string;
    dailyRecipientLimit: number;
};

export function TenantEmailSettings({
    settings,
    actionUrl,
    name,
    onSaved,
    onProcessingChange,
}: {
    settings: EmailSettings;
    actionUrl: string;
    name: string;
    onSaved: () => void;
    onProcessingChange: (processing: boolean) => void;
}) {
    useAdminTranslation();
    // No remember key: tokens and passwords never enter Inertia history/browser storage.
    const form = useForm({
        name,
        enabled: settings.enabled,
        from_address: settings.fromAddress,
        from_name: settings.fromName,
        smtp_token: '',
        daily_recipient_limit: String(settings.dailyRecipientLimit),
        current_password: '',
    });
    const problem = (form.errors as Record<string, string>).form;
    const busy = form.processing;
    return (
        <Card className="max-w-4xl">
            <CardHeader>
                <CardTitle>{t('Proton email')}</CardTitle>
            </CardHeader>
            <CardContent className="space-y-8">
                <ConfigurationForm
                    className="space-y-5"
                    autoComplete="off"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(actionUrl, {
                            preserveScroll: true,
                            onStart: () => onProcessingChange(true),
                            onSuccess: () => {
                                form.setDefaults({
                                    ...form.data,
                                    smtp_token: '',
                                    current_password: '',
                                });
                                onSaved();
                            },
                            onFinish: () => {
                                onProcessingChange(false);
                                form.setData((data) => ({
                                    ...data,
                                    smtp_token: '',
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
                    {problem && (
                        <Alert className="border-red-200 bg-red-50 text-red-800">
                            <AlertDescription>{errorMessage(problem)}</AlertDescription>
                        </Alert>
                    )}
                    <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
                        <div>
                            <label htmlFor="email-enabled" className="font-medium">
                                {t('Enable configuration')}
                            </label>
                        </div>
                        <Switch
                            id="email-enabled"
                            checked={form.data.enabled}
                            disabled={busy}
                            onCheckedChange={(value) => form.setData('enabled', value)}
                        />
                    </div>
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField id="email-host" label={t('SMTP server')}>
                            <Input id="email-host" value="smtp.protonmail.ch" readOnly />
                        </FormField>
                        <div className="grid grid-cols-2 gap-4">
                            <FormField id="email-port" label={t('SMTP port')}>
                                <Input id="email-port" value="587" readOnly />
                            </FormField>
                            <FormField id="email-encryption" label={t('Encryption')}>
                                <Input id="email-encryption" value="STARTTLS" readOnly />
                            </FormField>
                        </div>
                        <FormField
                            id="email-account"
                            label={t('SMTP account')}
                            description={t(
                                'Use the custom-domain address paired with your SMTP token.',
                            )}
                            error={errorMessage(form.errors.from_address)}
                        >
                            <Input
                                id="email-account"
                                type="email"
                                autoComplete="off"
                                required={form.data.enabled}
                                maxLength={254}
                                disabled={busy}
                                value={form.data.from_address}
                                onChange={(event) =>
                                    form.setData('from_address', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="email-token"
                            label={t('SMTP Token')}
                            description={
                                settings.tokenConfigured
                                    ? t(
                                          'Token configured. Leave blank to keep it; enter a new token to replace it.',
                                      )
                                    : t(
                                          'Enter the Proton-generated SMTP token, not your Proton login password.',
                                      )
                            }
                            error={errorMessage(form.errors.smtp_token)}
                        >
                            <Input
                                id="email-token"
                                type="password"
                                autoComplete="new-password"
                                maxLength={512}
                                required={form.data.enabled && !settings.tokenConfigured}
                                disabled={busy}
                                value={form.data.smtp_token}
                                onChange={(event) => form.setData('smtp_token', event.target.value)}
                            />
                        </FormField>
                        <FormField
                            id="email-from"
                            label={t('Sender email')}
                            description={t(
                                'The sender must match the SMTP account paired with the token.',
                            )}
                        >
                            <Input id="email-from" value={form.data.from_address} readOnly />
                        </FormField>
                        <FormField
                            id="email-name"
                            label={t('Sender name')}
                            error={errorMessage(form.errors.from_name)}
                        >
                            <Input
                                id="email-name"
                                required={form.data.enabled}
                                maxLength={100}
                                disabled={busy}
                                value={form.data.from_name}
                                onChange={(event) => form.setData('from_name', event.target.value)}
                            />
                        </FormField>
                        <FormField
                            id="email-limit"
                            label={t('Daily limit per recipient')}
                            description={t(
                                'Shared daily limit for registration, contact changes and password recovery per recipient in company time. 0 disables this daily limit; hourly protection still applies.',
                            )}
                            error={errorMessage(form.errors.daily_recipient_limit)}
                        >
                            <Input
                                id="email-limit"
                                type="number"
                                min={0}
                                max={1000}
                                step={1}
                                required
                                disabled={busy}
                                value={form.data.daily_recipient_limit}
                                onChange={(event) =>
                                    form.setData('daily_recipient_limit', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="email-password"
                            label={t('Current admin password')}
                            error={errorMessage(form.errors.current_password)}
                        >
                            <Input
                                id="email-password"
                                type="password"
                                autoComplete="current-password"
                                required
                                maxLength={255}
                                disabled={busy}
                                value={form.data.current_password}
                                onChange={(event) =>
                                    form.setData('current_password', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Proton SMTP requires a paid plan and an active custom-domain address. STARTTLS is required.',
                        )}{' '}
                        <a
                            className="underline"
                            href="https://proton.me/support/smtp-submission"
                            target="_blank"
                            rel="noreferrer"
                        >
                            {t('Proton setup guide')}
                        </a>
                    </p>
                    <Button type="submit" disabled={busy}>
                        {t('Save email settings')}
                    </Button>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}

export function CompanyEmailTest({
    actionUrl,
    available,
}: {
    actionUrl: string;
    available: boolean;
}) {
    useAdminTranslation();
    const test = useForm({ request_id: crypto.randomUUID(), test_email: '', current_password: '' });
    const busy = test.processing;
    const testProblem = (test.errors as Record<string, string>).form;
    return (
        <Card className="max-w-3xl">
            <CardContent className="pt-6">
                {' '}
                <ConfigurationForm
                    className="space-y-5"
                    autoComplete="off"
                    onSubmit={(event) => {
                        event.preventDefault();
                        test.post(actionUrl, {
                            preserveScroll: true,
                            onSuccess: () => test.setData('request_id', crypto.randomUUID()),
                            onFinish: () => test.setData('current_password', ''),
                        });
                    }}
                >
                    <h3 className="font-semibold">{t('Test email')}</h3>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Save and enable SMTP first. This sends one real test email using the saved configuration, not unsaved changes.',
                        )}
                    </p>
                    {testProblem && (
                        <Alert className="border-red-200 bg-red-50 text-red-800">
                            <AlertDescription>{errorMessage(testProblem)}</AlertDescription>
                        </Alert>
                    )}
                    <div className="grid gap-5 sm:grid-cols-2">
                        <FormField
                            id="email-test-recipient"
                            label={t('Test recipient')}
                            error={errorMessage(test.errors.test_email)}
                        >
                            <Input
                                id="email-test-recipient"
                                type="email"
                                required
                                maxLength={254}
                                disabled={busy}
                                value={test.data.test_email}
                                onChange={(event) =>
                                    test.setData({
                                        ...test.data,
                                        test_email: event.target.value,
                                        request_id: crypto.randomUUID(),
                                    })
                                }
                            />
                        </FormField>
                        <FormField
                            id="email-test-password"
                            label={t('Current admin password')}
                            error={errorMessage(test.errors.current_password)}
                        >
                            <Input
                                id="email-test-password"
                                type="password"
                                autoComplete="current-password"
                                required
                                maxLength={255}
                                disabled={busy}
                                value={test.data.current_password}
                                onChange={(event) =>
                                    test.setData('current_password', event.target.value)
                                }
                            />
                        </FormField>
                    </div>
                    <Button type="submit" variant="secondary" disabled={busy || !available}>
                        {t('Send test email')}
                    </Button>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}
