import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { displayMoney } from '@/lib/exact-amount';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { CompanyConfigurationHeader as PageHeader } from '@/components/admin/CompanyConfiguration';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import { MoneyInput } from '@/components/shared/MoneyInput';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { CompanyConfigurationLayout as TenantAdminLayout } from '@/components/admin/CompanyConfiguration';
import { TenantArticleSettings } from '@/components/admin/TenantArticleSettings';
import type { SmsSettings } from '@/components/admin/TenantSmsSettings';
import {
    CompanyNotificationProfile,
    type ProfileSelection,
} from '@/components/admin/CompanyNotificationProfile';
import type { EmailSettings } from '@/components/admin/TenantEmailSettings';
import type { TenantArticleContent } from '@/lib/tenant-articles';

type SettingsData = {
    branding: {
        brandName: string;
        primaryColor: string;
        supportEmail: string | null;
        supportUrl: string | null;
        copyrightText: string | null;
        logoUrl: string | null;
        faviconUrl: string | null;
    };
    locales: { locale: string; enabled: boolean; default: boolean }[];
    business: {
        depositAmount: string;
        depositAsset: string;
        depositRefundWaitDays: number | null;
        withdrawalFeePercent: string | null;
    };
    kyc: { enabled: boolean; maxAccountsPerIdentity: number | null; reviewMode: string };
    supportedLocales: string[];
    supportedAssets: string[];
    articles?: TenantArticleContent[];
    sms?: SmsSettings & ProfileSelection;
    email?: EmailSettings & ProfileSelection;
};
const sections = [
    { key: 'branding', label: 'Branding' },
    { key: 'locales', label: 'Locales' },
    { key: 'business', label: 'Business rules' },
    { key: 'articles', label: 'About us articles' },
    { key: 'sms', label: 'Aliyun SMS' },
    { key: 'email', label: 'Proton email' },
];

export default function Settings({
    section,
    settings,
}: {
    section: string;
    settings: SettingsData;
}) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const { configurationBase } = usePage<SharedProps>().props;
    return (
        <TenantAdminLayout>
            <Head title={t('Tenant settings')} />
            <div className="space-y-6">
                <PageHeader title={t('Settings')} />
                {!configurationBase && (
                    <div className="flex gap-2 overflow-x-auto pb-1">
                        {sections.map((item) => (
                            <Button
                                key={item.key}
                                asChild
                                variant={section === item.key ? 'default' : 'secondary'}
                                size="sm"
                                className="shrink-0 whitespace-nowrap"
                            >
                                <Link href={configurationUrl(`/admin/settings/${item.key}`)}>
                                    {t(item.label)}
                                </Link>
                            </Button>
                        ))}
                    </div>
                )}
                {section === 'branding' && <BrandingForm settings={settings} />}
                {section === 'locales' && <LocalesForm settings={settings} />}
                {section === 'business' && <BusinessForm settings={settings} />}
                {section === 'kyc' && (
                    <Card className="max-w-3xl">
                        <CardHeader>
                            <CardTitle>{t('KYC policy')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <dl className="grid gap-5 sm:grid-cols-3">
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        {t('KYC enabled')}
                                    </dt>
                                    <dd>{t(settings.kyc.enabled ? 'Enabled' : 'Disabled')}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        {t('Maximum accounts per identity')}
                                    </dt>
                                    <dd>{settings.kyc.maxAccountsPerIdentity}</dd>
                                </div>
                                <div>
                                    <dt className="text-sm text-muted-foreground">
                                        {t('Review mode')}
                                    </dt>
                                    <dd>
                                        {t(
                                            settings.kyc.reviewMode === 'AUTOMATIC'
                                                ? 'Automatic approval'
                                                : 'MANUAL',
                                        )}
                                    </dd>
                                </div>
                            </dl>
                        </CardContent>
                    </Card>
                )}
                {section === 'sms' && settings.sms && (
                    <CompanyNotificationProfile key="sms" channel="sms" settings={settings.sms} />
                )}
                {section === 'email' && settings.email && (
                    <CompanyNotificationProfile
                        key="email"
                        channel="email"
                        settings={settings.email}
                    />
                )}
                {section === 'articles' && (
                    <TenantArticleSettings
                        articles={settings.articles ?? []}
                        locales={settings.supportedLocales}
                    />
                )}
            </div>
        </TenantAdminLayout>
    );
}

function BrandingForm({ settings }: { settings: SettingsData }) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm<{
        brand_name: string;
        primary_color: string;
        support_email: string;
        support_url: string;
        copyright_text: string;
        logo: File | null;
        favicon: File | null;
    }>({
        brand_name: settings.branding.brandName,
        primary_color: settings.branding.primaryColor,
        support_email: settings.branding.supportEmail ?? '',
        support_url: settings.branding.supportUrl ?? '',
        copyright_text: settings.branding.copyrightText ?? '',
        logo: null,
        favicon: null,
    });
    return (
        <>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>{t('Brand and support')}</CardTitle>
                </CardHeader>
                <CardContent>
                    <ConfigurationForm
                        className="grid gap-5 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post(configurationUrl('/admin/settings/branding'), {
                                forceFormData: true,
                            });
                        }}
                    >
                        <FormField
                            id="brand-name"
                            label={t('Brand name')}
                            error={errorMessage(form.errors.brand_name)}
                        >
                            <Input
                                id="brand-name"
                                value={form.data.brand_name}
                                onChange={(event) => form.setData('brand_name', event.target.value)}
                            />
                        </FormField>
                        <FormField
                            id="primary-color"
                            label={t('Primary color')}
                            description={t('Strict six-digit HEX.')}
                            error={errorMessage(form.errors.primary_color)}
                        >
                            <div className="flex gap-2">
                                <input
                                    aria-label={t('Color picker')}
                                    className="h-10 w-12 rounded-lg border bg-surface p-1"
                                    type="color"
                                    value={form.data.primary_color}
                                    onChange={(event) =>
                                        form.setData(
                                            'primary_color',
                                            event.target.value.toUpperCase(),
                                        )
                                    }
                                />
                                <Input
                                    id="primary-color"
                                    value={form.data.primary_color}
                                    onChange={(event) =>
                                        form.setData('primary_color', event.target.value)
                                    }
                                />
                            </div>
                        </FormField>
                        <FormField
                            id="support-email"
                            label={t('Support email')}
                            error={errorMessage(form.errors.support_email)}
                        >
                            <Input
                                id="support-email"
                                type="email"
                                value={form.data.support_email}
                                onChange={(event) =>
                                    form.setData('support_email', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="support-url"
                            label={t('Support URL')}
                            error={errorMessage(form.errors.support_url)}
                        >
                            <Input
                                id="support-url"
                                type="url"
                                value={form.data.support_url}
                                onChange={(event) =>
                                    form.setData('support_url', event.target.value)
                                }
                            />
                        </FormField>
                        <FormField
                            id="logo"
                            label={t('Logo')}
                            description={t('PNG, JPG or WEBP up to 2 MB.')}
                            error={errorMessage(form.errors.logo)}
                        >
                            <Input
                                id="logo"
                                type="file"
                                accept=".png,.jpg,.jpeg,.webp"
                                onChange={(event) =>
                                    form.setData('logo', event.target.files?.[0] ?? null)
                                }
                            />
                        </FormField>
                        <FormField
                            id="favicon"
                            label={t('Favicon')}
                            description={t('PNG or ICO up to 512 KB.')}
                            error={errorMessage(form.errors.favicon)}
                        >
                            <Input
                                id="favicon"
                                type="file"
                                accept=".png,.ico"
                                onChange={(event) =>
                                    form.setData('favicon', event.target.files?.[0] ?? null)
                                }
                            />
                        </FormField>
                        <FormField
                            id="copyright"
                            label={t('Copyright text')}
                            error={errorMessage(form.errors.copyright_text)}
                        >
                            <Input
                                id="copyright"
                                value={form.data.copyright_text}
                                onChange={(event) =>
                                    form.setData('copyright_text', event.target.value)
                                }
                            />
                        </FormField>
                        <div className="flex items-end sm:justify-end">
                            <Button className="w-full sm:w-auto" disabled={form.processing}>
                                {t('Save branding')}
                            </Button>
                        </div>
                    </ConfigurationForm>
                </CardContent>
            </Card>
        </>
    );
}

function LocalesForm({ settings }: { settings: SettingsData }) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const initialEnabled = settings.locales
        .filter((locale) => locale.enabled)
        .map((locale) => locale.locale);
    const form = useForm({
        enabled_locales: initialEnabled,
        default_locale:
            settings.locales.find((locale) => locale.default)?.locale ?? initialEnabled[0],
    });
    const toggle = (locale: string, checked: boolean) =>
        form.setData(
            'enabled_locales',
            checked
                ? [...new Set([...form.data.enabled_locales, locale])]
                : form.data.enabled_locales.filter((value) => value !== locale),
        );
    return (
        <Card className="max-w-3xl">
            <CardHeader>
                <CardTitle>{t('Locales')}</CardTitle>
            </CardHeader>
            <CardContent>
                <ConfigurationForm
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(configurationUrl('/admin/settings/locales'));
                    }}
                >
                    <div className="grid gap-3 sm:grid-cols-2">
                        {settings.supportedLocales.map((locale) => (
                            <label
                                key={locale}
                                className="flex items-center gap-3 rounded-lg border p-4"
                            >
                                <Checkbox
                                    checked={form.data.enabled_locales.includes(locale)}
                                    onCheckedChange={(checked) => toggle(locale, checked === true)}
                                />
                                <span className="font-medium">{t(locale)}</span>
                            </label>
                        ))}
                    </div>
                    {form.errors.enabled_locales && (
                        <p className="text-sm text-danger">
                            {errorMessage(form.errors.enabled_locales)}
                        </p>
                    )}
                    <FormField
                        id="default-locale"
                        label={t('Default locale')}
                        error={errorMessage(form.errors.default_locale)}
                    >
                        <Select
                            value={form.data.default_locale}
                            onValueChange={(value) => form.setData('default_locale', value)}
                        >
                            <SelectTrigger id="default-locale">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {form.data.enabled_locales.map((locale) => (
                                    <SelectItem key={locale} value={locale}>
                                        {t(locale)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <Button disabled={form.processing}>{t('Save locales atomically')}</Button>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}

function BusinessForm({ settings }: { settings: SettingsData }) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const { configurationBase } = usePage<SharedProps>().props;
    const form = useForm({
        required_security_deposit_amount: settings.business.depositAmount,
        security_deposit_refund_wait_days:
            settings.business.depositRefundWaitDays === null
                ? ''
                : String(settings.business.depositRefundWaitDays),
        withdrawal_fee_percent: settings.business.withdrawalFeePercent ?? '',
    });
    return (
        <Card className="max-w-3xl">
            <CardHeader>
                <CardTitle>{t('Business configuration')}</CardTitle>
            </CardHeader>
            <CardContent>
                <ConfigurationForm
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post(configurationUrl('/admin/settings/business'), {
                            preserveScroll: true,
                        });
                    }}
                >
                    {configurationBase ? (
                        <>
                            <div className="grid gap-5 sm:grid-cols-2">
                                <FormField
                                    id="company-deposit-amount"
                                    label={`${t('Required security deposit')} (${settings.business.depositAsset})`}
                                    error={errorMessage(
                                        form.errors.required_security_deposit_amount,
                                    )}
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
                                    error={errorMessage(
                                        form.errors.security_deposit_refund_wait_days,
                                    )}
                                >
                                    <Input
                                        id="company-refund-wait"
                                        type="number"
                                        min="0"
                                        max="3650"
                                        step="1"
                                        placeholder={t('Not configured')}
                                        value={form.data.security_deposit_refund_wait_days}
                                        onChange={(event) =>
                                            form.setData(
                                                'security_deposit_refund_wait_days',
                                                event.target.value,
                                            )
                                        }
                                    />
                                </FormField>
                            </div>
                            <p className="text-sm text-muted-foreground">
                                {t(
                                    'Changes apply to new refund requests only. Existing deposits and refund deadlines remain unchanged.',
                                )}
                            </p>
                        </>
                    ) : (
                        <dl className="grid gap-5 sm:grid-cols-2">
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    {t('Required security deposit')}
                                </dt>
                                <dd>
                                    {displayMoney(settings.business.depositAmount)}{' '}
                                    {settings.business.depositAsset}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-sm text-muted-foreground">
                                    {t('Deposit refund waiting period (days)')}
                                </dt>
                                <dd>
                                    {settings.business.depositRefundWaitDays ?? t('Not configured')}
                                </dd>
                            </div>
                        </dl>
                    )}
                    <FormField
                        id="withdrawal-fee-percent"
                        label={t('Withdrawal fee (%)')}
                        description={t(
                            'Deducted from each withdrawal amount. 0 means no fee. Changes apply only to new requests.',
                        )}
                        error={errorMessage(form.errors.withdrawal_fee_percent)}
                    >
                        <Input
                            id="withdrawal-fee-percent"
                            inputMode="decimal"
                            value={form.data.withdrawal_fee_percent}
                            onChange={(event) =>
                                form.setData('withdrawal_fee_percent', event.target.value)
                            }
                            required
                        />
                    </FormField>
                    <Button disabled={form.processing}>{t('Save business configuration')}</Button>
                </ConfigurationForm>
            </CardContent>
        </Card>
    );
}
