import { Head, Link, useForm } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

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
        allowWalletTopup: boolean;
        allowWithdrawal: boolean;
    };
    kyc: { enabled: boolean; maxAccountsPerIdentity: number | null; reviewMode: string };
    supportedLocales: string[];
    supportedAssets: string[];
};
const sections = [
    { key: 'branding', label: 'Branding' },
    { key: 'locales', label: 'Locales' },
    { key: 'business', label: 'Business rules' },
    { key: 'kyc', label: 'KYC' },
];

export default function Settings({
    section,
    settings,
}: {
    section: string;
    settings: SettingsData;
}) {
    return (
        <TenantAdminLayout>
            <Head title="Tenant settings" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Tenant configuration"
                    title="Settings"
                    description="Controlled white-label and operating configuration shared by all tenant surfaces."
                />
                <div className="flex gap-2 overflow-x-auto pb-1">
                    {sections.map((item) => (
                        <Button
                            key={item.key}
                            asChild
                            variant={section === item.key ? 'default' : 'secondary'}
                            size="sm"
                        >
                            <Link href={`/admin/settings/${item.key}`}>{item.label}</Link>
                        </Button>
                    ))}
                </div>
                {section === 'branding' && <BrandingForm settings={settings} />}
                {section === 'locales' && <LocalesForm settings={settings} />}
                {section === 'business' && <BusinessForm settings={settings} />}
                {section === 'kyc' && <KycForm settings={settings} />}
            </div>
        </TenantAdminLayout>
    );
}

function BrandingForm({ settings }: { settings: SettingsData }) {
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
            <Alert>
                <AlertTitle>Safe white-label boundary</AlertTitle>
                <AlertDescription>
                    Logo, favicon, brand name, primary color and support details are supported.
                    Custom CSS, JavaScript and layouts are not accepted.
                </AlertDescription>
            </Alert>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>Brand and support</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        className="grid gap-5 sm:grid-cols-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/admin/settings/branding', { forceFormData: true });
                        }}
                    >
                        <FormField
                            id="brand-name"
                            label="Brand name"
                            error={form.errors.brand_name}
                        >
                            <Input
                                id="brand-name"
                                value={form.data.brand_name}
                                onChange={(event) => form.setData('brand_name', event.target.value)}
                            />
                        </FormField>
                        <FormField
                            id="primary-color"
                            label="Primary color"
                            description="Strict six-digit HEX."
                            error={form.errors.primary_color}
                        >
                            <div className="flex gap-2">
                                <input
                                    aria-label="Color picker"
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
                            label="Support email"
                            error={form.errors.support_email}
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
                            label="Support URL"
                            error={form.errors.support_url}
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
                            label="Logo"
                            description="PNG, JPG or WEBP up to 2 MB."
                            error={form.errors.logo}
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
                            label="Favicon"
                            description="PNG or ICO up to 512 KB."
                            error={form.errors.favicon}
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
                            label="Copyright text"
                            error={form.errors.copyright_text}
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
                                Save branding
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

function LocalesForm({ settings }: { settings: SettingsData }) {
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
                <CardTitle>Locales</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    className="space-y-6"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.post('/admin/settings/locales');
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
                                <span className="font-medium">{locale}</span>
                            </label>
                        ))}
                    </div>
                    {form.errors.enabled_locales && (
                        <p className="text-sm text-danger">{form.errors.enabled_locales}</p>
                    )}
                    <FormField
                        id="default-locale"
                        label="Default locale"
                        error={form.errors.default_locale}
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
                                        {locale}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <Button disabled={form.processing}>Save locales atomically</Button>
                </form>
            </CardContent>
        </Card>
    );
}

function BusinessForm({ settings }: { settings: SettingsData }) {
    const form = useForm({
        required_security_deposit_amount: settings.business.depositAmount,
        required_security_deposit_asset: settings.business.depositAsset,
        allow_wallet_topup: settings.business.allowWalletTopup,
        allow_withdrawal: settings.business.allowWithdrawal,
    });
    return (
        <>
            <Alert>
                <AlertTitle>Configuration only</AlertTitle>
                <AlertDescription>
                    These values do not move funds, create balances, or write a ledger. Formal money
                    workflows arrive in a later phase.
                </AlertDescription>
            </Alert>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>Business configuration</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/admin/settings/business');
                        }}
                    >
                        <div className="grid gap-5 sm:grid-cols-2">
                            <FormField
                                id="deposit"
                                label="Required security deposit"
                                description="Decimal string; never a floating-point value."
                                error={form.errors.required_security_deposit_amount}
                            >
                                <Input
                                    id="deposit"
                                    inputMode="decimal"
                                    value={form.data.required_security_deposit_amount}
                                    onChange={(event) =>
                                        form.setData(
                                            'required_security_deposit_amount',
                                            event.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="deposit-asset"
                                label="Deposit asset"
                                error={form.errors.required_security_deposit_asset}
                            >
                                <Select
                                    value={form.data.required_security_deposit_asset}
                                    onValueChange={(value) =>
                                        form.setData('required_security_deposit_asset', value)
                                    }
                                >
                                    <SelectTrigger id="deposit-asset">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {settings.supportedAssets.map((asset) => (
                                            <SelectItem key={asset} value={asset}>
                                                {asset}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </div>
                        <ToggleRow
                            label="Allow wallet top-up"
                            description="Policy flag only; no top-up workflow exists."
                            checked={form.data.allow_wallet_topup}
                            onChange={(value) => form.setData('allow_wallet_topup', value)}
                        />
                        <ToggleRow
                            label="Allow withdrawal"
                            description="Policy flag only; no withdrawal workflow exists."
                            checked={form.data.allow_withdrawal}
                            onChange={(value) => form.setData('allow_withdrawal', value)}
                        />
                        <Button disabled={form.processing}>Save business configuration</Button>
                    </form>
                </CardContent>
            </Card>
        </>
    );
}

function KycForm({ settings }: { settings: SettingsData }) {
    const form = useForm({
        enabled: settings.kyc.enabled,
        max_accounts_per_identity: settings.kyc.maxAccountsPerIdentity?.toString() ?? '1',
        review_mode: 'MANUAL',
    });
    return (
        <>
            <Alert>
                <AlertTitle>Manual review policy</AlertTitle>
                <AlertDescription>
                    KYC submissions require explicit review. OCR can assist reviewers but never
                    approves an identity automatically.
                </AlertDescription>
            </Alert>
            <Card className="max-w-3xl">
                <CardHeader>
                    <CardTitle>KYC policy</CardTitle>
                </CardHeader>
                <CardContent>
                    <form
                        className="space-y-5"
                        onSubmit={(event) => {
                            event.preventDefault();
                            form.post('/admin/settings/kyc');
                        }}
                    >
                        <ToggleRow
                            label="KYC enabled"
                            description="Controls whether active users can submit identity documents."
                            checked={form.data.enabled}
                            onChange={(value) => form.setData('enabled', value)}
                        />
                        <FormField
                            id="identity-limit"
                            label="Maximum accounts per identity"
                            error={form.errors.max_accounts_per_identity}
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
                        <FormField id="review-mode" label="Review mode">
                            <Input id="review-mode" value="MANUAL" disabled />
                        </FormField>
                        <Button disabled={form.processing}>Save KYC configuration</Button>
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
    return (
        <div className="flex items-center justify-between gap-4 rounded-lg border p-4">
            <div>
                <p className="font-medium">{label}</p>
                <p className="text-sm text-muted-foreground">{description}</p>
            </div>
            <Switch checked={checked} onCheckedChange={onChange} />
        </div>
    );
}
