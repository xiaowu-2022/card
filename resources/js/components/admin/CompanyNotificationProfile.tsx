import { useForm, usePage } from '@inertiajs/react';
import { t, errorMessage, useAdminTranslation } from '@/i18n/admin';
import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { CompanyEmailTest } from '@/components/admin/TenantEmailSettings';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
export type ProfileSelection = {
    profileId: string | null;
    profileName: string | null;
    available: boolean;
    profiles?: { id: string; name: string; available: boolean }[];
};
export function CompanyNotificationProfile({
    channel,
    settings,
}: {
    channel: 'sms' | 'email';
    settings: ProfileSelection;
}) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const { configurationBase } = usePage<{ configurationBase?: string }>().props;
    const form = useForm({ profile_id: settings.profileId ?? '', current_password: '' });
    return (
        <div className="space-y-6">
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>{t(channel === 'sms' ? 'Aliyun SMS' : 'Proton email')}</CardTitle>
                </CardHeader>
                <CardContent>
                    {configurationBase ? (
                        <ConfigurationForm
                            className="space-y-5"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.transform((data) => ({
                                    ...data,
                                    profile_id: data.profile_id || null,
                                }));
                                form.post(configurationUrl(`/admin/settings/${channel}`), {
                                    preserveScroll: true,
                                    onSuccess: () =>
                                        form.setDefaults({ ...form.data, current_password: '' }),
                                    onFinish: () => form.setData('current_password', ''),
                                });
                            }}
                        >
                            <FormField
                                id="notification-profile"
                                label={t('Selected configuration')}
                                error={errorMessage(form.errors.profile_id)}
                            >
                                <Select
                                    value={form.data.profile_id || 'none'}
                                    onValueChange={(value) =>
                                        form.setData('profile_id', value === 'none' ? '' : value)
                                    }
                                    disabled={form.processing}
                                >
                                    <SelectTrigger id="notification-profile">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="none">{t('Not configured')}</SelectItem>
                                        {settings.profiles?.map((profile) => (
                                            <SelectItem
                                                key={profile.id}
                                                value={profile.id}
                                                disabled={!profile.available}
                                            >
                                                {profile.name}
                                                {!profile.available ? ` · ${t('Unavailable')}` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="notification-password"
                                label={t('Current admin password')}
                                error={errorMessage(form.errors.current_password)}
                            >
                                <Input
                                    id="notification-password"
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
                            {(form.errors as Record<string, string>).form && (
                                <p className="text-sm text-destructive">
                                    {errorMessage((form.errors as Record<string, string>).form)}
                                </p>
                            )}
                            <Button disabled={form.processing}>
                                {t('Save configuration selection')}
                            </Button>
                        </ConfigurationForm>
                    ) : (
                        <p>
                            {settings.profileName ?? t('Not configured')}
                            {settings.profileName && !settings.available
                                ? ` · ${t('Unavailable')}`
                                : ''}
                        </p>
                    )}
                </CardContent>
            </Card>
            {channel === 'email' && configurationBase && (
                <CompanyEmailTest
                    key={settings.profileId ?? 'none'}
                    actionUrl={configurationUrl('/admin/settings/email/test')}
                    available={
                        settings.available && form.data.profile_id === (settings.profileId ?? '')
                    }
                />
            )}
        </div>
    );
}
