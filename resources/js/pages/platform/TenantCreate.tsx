import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { Head, Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { PlatformLayout } from '@/layouts/PlatformLayout';

export default function TenantCreate({
    locales,
    assets,
    timezones,
}: {
    locales: string[];
    assets: string[];
    timezones: string[];
}) {
    useAdminTranslation();
    const form = useForm({
        name: '',
        slug: '',
        owner_email: '',
        default_locale: locales[0] ?? 'en',
        timezone: 'UTC',
        default_asset: assets[0] ?? 'USDT',
    });
    return (
        <PlatformLayout>
            <Head title={t('Create tenant')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Tenant onboarding')}
                    title={t('Create tenant foundation')}
                    description={t(
                        'Creates a draft tenant, its immutable system domain, baseline configuration, and a Tenant Owner invitation.',
                    )}
                    actions={
                        <Button asChild variant="secondary">
                            <Link href="/platform/tenants">{t('Cancel')}</Link>
                        </Button>
                    }
                />
                <Alert>
                    <AlertTitle>{t('No business activation')}</AlertTitle>
                    <AlertDescription>
                        {t(
                            'This step creates administrative foundation only. Wallet, card, funding and provider workflows remain unavailable.',
                        )}
                    </AlertDescription>
                </Alert>
                <Card className="max-w-3xl">
                    <CardHeader>
                        <CardTitle>{t('Tenant and owner')}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <form
                            className="grid gap-5 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post('/platform/tenants');
                            }}
                        >
                            <FormField
                                id="name"
                                label={t('Legal or operating name')}
                                error={errorMessage(form.errors.name)}
                            >
                                <Input
                                    id="name"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    autoFocus
                                />
                            </FormField>
                            <FormField
                                id="slug"
                                label={t('System subdomain')}
                                description={t('Lowercase letters, numbers and hyphens.')}
                                error={errorMessage(form.errors.slug)}
                            >
                                <Input
                                    id="slug"
                                    value={form.data.slug}
                                    onChange={(event) =>
                                        form.setData('slug', event.target.value.toLowerCase())
                                    }
                                    placeholder="acme"
                                />
                            </FormField>
                            <FormField
                                id="owner-email"
                                label={t('Owner email')}
                                error={errorMessage(form.errors.owner_email)}
                            >
                                <Input
                                    id="owner-email"
                                    type="email"
                                    value={form.data.owner_email}
                                    onChange={(event) =>
                                        form.setData('owner_email', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="locale"
                                label={t('Default locale')}
                                error={errorMessage(form.errors.default_locale)}
                            >
                                <Select
                                    value={form.data.default_locale}
                                    onValueChange={(value) => form.setData('default_locale', value)}
                                >
                                    <SelectTrigger id="locale">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {locales.map((locale) => (
                                            <SelectItem key={locale} value={locale}>
                                                {t(locale)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="timezone"
                                label={t('Timezone')}
                                error={errorMessage(form.errors.timezone)}
                            >
                                <Select
                                    value={form.data.timezone}
                                    onValueChange={(value) => form.setData('timezone', value)}
                                >
                                    <SelectTrigger id="timezone">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {timezones.map((timezone) => (
                                            <SelectItem key={timezone} value={timezone}>
                                                {timezone}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="asset"
                                label={t('Default asset')}
                                description={t('Configuration only; no account is created.')}
                                error={errorMessage(form.errors.default_asset)}
                            >
                                <Input id="asset" value={form.data.default_asset} readOnly />
                            </FormField>
                            <div className="flex items-end sm:justify-end">
                                <Button
                                    className="w-full sm:w-auto"
                                    type="submit"
                                    disabled={form.processing}
                                >
                                    {t('Create and invite owner')}
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>
            </div>
        </PlatformLayout>
    );
}
