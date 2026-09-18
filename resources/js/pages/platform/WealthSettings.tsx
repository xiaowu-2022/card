import { Head, useForm } from '@inertiajs/react';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { CompanyConfigurationLayout } from '@/components/admin/CompanyConfiguration';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import type { WealthSetting } from '@/pages/user/Wealth';
import { exactAmount } from '@/lib/exact-amount';

function settingsForForm(settings: WealthSetting[]): WealthSetting[] {
    return settings.map((setting) => ({
        ...setting,
        minimum: setting.minimum === '' ? '' : exactAmount(setting.minimum),
    }));
}

export default function WealthSettings({
    company,
    settings,
    readOnly,
}: {
    company: { id: string; name: string };
    settings: WealthSetting[];
    readOnly: boolean;
}) {
    useAdminTranslation();
    const form = useForm({ settings: settingsForForm(settings) });
    const Layout = readOnly ? TenantAdminLayout : CompanyConfigurationLayout;
    const update = (index: number, next: WealthSetting) =>
        form.setData(
            'settings',
            form.data.settings.map((s, i) => (i === index ? next : s)),
        );
    return (
        <Layout>
            <Head title={t('Wealth settings')} />
            <div className="space-y-6">
                <h1 className="text-2xl font-semibold">
                    {company.name} · {t('Wealth settings')}
                </h1>
                <p>
                    {t(
                        'Settings affect new deposits only. Existing deposits retain their original terms.',
                    )}
                </p>
                {readOnly && (
                    <p>
                        {t(
                            'Managed by SaaS Platform. Company administrators have read-only access.',
                        )}
                    </p>
                )}
                <form
                    className="space-y-5"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(`/platform/tenants/${company.id}/configuration/wealth`, {
                            onSuccess: (page) =>
                                form.setData(
                                    'settings',
                                    settingsForForm(page.props.settings as WealthSetting[]),
                                ),
                        });
                    }}
                >
                    <fieldset disabled={readOnly || form.processing} className="space-y-6">
                        {form.data.settings.map((s, i) => (
                            <section
                                className="space-y-4 rounded-xl border bg-surface p-4"
                                key={s.asset}
                            >
                                <h2 className="font-semibold">{s.asset}</h2>
                                <FormField
                                    id={`minimum-${s.asset}`}
                                    label={t('Minimum wealth deposit')}
                                >
                                    <Input
                                        id={`minimum-${s.asset}`}
                                        inputMode="decimal"
                                        value={s.minimum}
                                        onChange={(e) =>
                                            update(i, { ...s, minimum: e.target.value })
                                        }
                                    />
                                </FormField>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                                    {s.products.map((p, j) => (
                                        <div
                                            className="space-y-2 rounded-lg border p-3"
                                            key={p.months}
                                        >
                                            <label className="flex items-center gap-2">
                                                <input
                                                    type="checkbox"
                                                    checked={p.enabled}
                                                    onChange={(e) =>
                                                        update(i, {
                                                            ...s,
                                                            products: s.products.map((v, k) =>
                                                                k === j
                                                                    ? {
                                                                          ...v,
                                                                          enabled: e.target.checked,
                                                                      }
                                                                    : v,
                                                            ),
                                                        })
                                                    }
                                                />
                                                {t('{{months}} months', { months: p.months })}
                                            </label>
                                            <FormField
                                                id={`rate-${s.asset}-${p.months}`}
                                                label={t('Annual interest rate (%)')}
                                            >
                                                <Input
                                                    id={`rate-${s.asset}-${p.months}`}
                                                    inputMode="decimal"
                                                    value={p.rate}
                                                    onChange={(e) =>
                                                        update(i, {
                                                            ...s,
                                                            products: s.products.map((v, k) =>
                                                                k === j
                                                                    ? { ...v, rate: e.target.value }
                                                                    : v,
                                                            ),
                                                        })
                                                    }
                                                />
                                            </FormField>
                                        </div>
                                    ))}
                                </div>
                            </section>
                        ))}
                    </fieldset>
                    {Object.values(form.errors).map((e, i) => (
                        <p key={i} role="alert" className="text-sm text-destructive">
                            {errorMessage(e)}
                        </p>
                    ))}
                    {!readOnly && <Button disabled={form.processing}>{t('Save settings')}</Button>}
                </form>
            </div>
        </Layout>
    );
}
