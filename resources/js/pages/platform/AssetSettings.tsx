import { Head, Link, router, useForm } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';

type Rail = { code: string; asset: string; network: string; address: string; enabled: boolean };
type Props = {
    companies: { id: string; name: string }[];
    company: string | null;
    market: { enabled: boolean; configured: boolean };
    networks: {
        network: string;
        enabled: boolean;
        rpc_url: string;
        start_height: number | null;
        next_height: number | null;
        confirmations: number;
        configured: boolean;
    }[];
    rails: Rail[];
    companyRails: {
        rail_code: string;
        deposit_enabled: boolean;
        withdrawal_enabled: boolean;
        minimum_deposit: string | null;
        withdrawal_fee: string | null;
    }[];
    policies: {
        asset_code: string;
        enabled: boolean;
        fee_percent: string | null;
        single_limit: string | null;
        daily_limit: string | null;
    }[];
};
type Field = {
    name: string;
    label: string;
    value: string | boolean;
    secret?: boolean;
    readOnly?: boolean;
};
export default function AssetSettings(p: Props) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('Multi-currency settings')} />
            <div className="space-y-6">
                <PageHeader title={t('Multi-currency settings')} />
                <p className="text-sm text-muted-foreground">
                    {t(
                        'New networks start disabled. Configure a scan boundary before enabling. Existing history is never replayed.',
                    )}
                </p>
                <div className="flex flex-wrap gap-3">
                    <Link href="/platform/asset-deposits" className="underline">
                        {t('Multi-currency deposits')}
                    </Link>
                    <Link href="/platform/asset-withdrawals" className="underline">
                        {t('Multi-currency withdrawals')}
                    </Link>
                </div>
                <label className="block max-w-md space-y-2 text-sm">
                    <span>{t('Company')}</span>
                    <select
                        className="min-h-11 w-full rounded-md border bg-surface px-3"
                        value={p.company ?? ''}
                        onChange={(e) =>
                            router.get(
                                '/platform/settings/assets',
                                e.target.value ? { company: e.target.value } : {},
                            )
                        }
                    >
                        <option value="">{t('Global configuration')}</option>
                        {p.companies.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                </label>
                {!p.company ? (
                    <>
                        <ConfigForm
                            title={t('Market prices')}
                            kind="market"
                            fields={[
                                { name: 'enabled', label: 'Enabled', value: p.market.enabled },
                                {
                                    name: 'api_key',
                                    label: p.market.configured ? 'Replace API key' : 'API key',
                                    value: '',
                                    secret: true,
                                },
                            ]}
                        />
                        {p.networks.map((n) => (
                            <ConfigForm
                                key={n.network}
                                title={n.network}
                                kind="network"
                                fixed={{ network: n.network }}
                                fields={[
                                    { name: 'enabled', label: 'Enabled', value: n.enabled },
                                    {
                                        name: 'rpc_url',
                                        label: 'HTTPS RPC endpoint',
                                        value: n.rpc_url,
                                    },
                                    {
                                        name: 'username',
                                        label: 'RPC username (optional)',
                                        value: '',
                                    },
                                    {
                                        name: 'credential',
                                        label: n.configured
                                            ? 'Replace node credential'
                                            : 'Node credential',
                                        value: '',
                                        secret: true,
                                    },
                                    {
                                        name: 'start_height',
                                        label: 'Initial scan block',
                                        value: n.start_height?.toString() ?? '',
                                        readOnly: n.start_height !== null,
                                    },
                                    {
                                        name: 'confirmations',
                                        label: 'Bitcoin confirmations (minimum 6)',
                                        value: n.confirmations.toString(),
                                    },
                                ]}
                                extra={`${t('Next scan block')}: ${n.next_height ?? '—'}`}
                            />
                        ))}
                        {p.rails.map((r) => (
                            <ConfigForm
                                key={r.code}
                                title={`${r.asset} · ${r.network}`}
                                kind="rail"
                                fixed={{ code: r.code }}
                                fields={[
                                    { name: 'enabled', label: 'Enabled', value: r.enabled },
                                    {
                                        name: 'address',
                                        label: 'Receiving address',
                                        value: r.address,
                                    },
                                ]}
                            />
                        ))}
                    </>
                ) : (
                    <>
                        <h2 className="font-semibold">{t('Deposit and withdrawal networks')}</h2>
                        {p.rails.map((r) => {
                            const c = p.companyRails.find((c) => c.rail_code === r.code);
                            return (
                                <ConfigForm
                                    key={r.code}
                                    company={p.company!}
                                    title={`${r.asset} · ${r.network}`}
                                    kind="company-rail"
                                    fixed={{ code: r.code }}
                                    fields={[
                                        {
                                            name: 'deposit_enabled',
                                            label: 'Deposits enabled',
                                            value: c?.deposit_enabled ?? false,
                                        },
                                        {
                                            name: 'withdrawal_enabled',
                                            label: 'Withdrawals enabled',
                                            value: c?.withdrawal_enabled ?? false,
                                        },
                                        {
                                            name: 'minimum',
                                            label: 'Minimum deposit',
                                            value: trim(c?.minimum_deposit),
                                        },
                                        {
                                            name: 'fee',
                                            label: 'Withdrawal fee (original currency)',
                                            value: trim(c?.withdrawal_fee),
                                        },
                                    ]}
                                />
                            );
                        })}
                        <h2 className="font-semibold">{t('Internal exchange')}</h2>
                        {['USDC', 'ETH', 'BTC'].map((asset) => {
                            const c = p.policies.find((c) => c.asset_code === asset);
                            return (
                                <ConfigForm
                                    key={asset}
                                    company={p.company!}
                                    title={`${asset} → USDT`}
                                    kind="exchange"
                                    fixed={{ asset }}
                                    fields={[
                                        {
                                            name: 'enabled',
                                            label: 'Enabled',
                                            value: c?.enabled ?? false,
                                        },
                                        {
                                            name: 'fee',
                                            label: 'Fee percentage',
                                            value: trim(c?.fee_percent),
                                        },
                                        {
                                            name: 'single',
                                            label: 'Single exchange limit (USDT)',
                                            value: trim(c?.single_limit),
                                        },
                                        {
                                            name: 'daily',
                                            label: 'Daily user limit (USDT)',
                                            value: trim(c?.daily_limit),
                                        },
                                    ]}
                                />
                            );
                        })}
                    </>
                )}
            </div>
        </PlatformLayout>
    );
}
function trim(v: string | null | undefined) {
    return v?.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') ?? '';
}
function ConfigForm({
    title,
    kind,
    fields,
    fixed = {},
    company,
    extra,
}: {
    title: string;
    kind: string;
    fields: Field[];
    fixed?: Record<string, string>;
    company?: string;
    extra?: string;
}) {
    const form = useForm<Record<string, string | boolean>>({
        kind,
        ...fixed,
        ...Object.fromEntries(fields.map((f) => [f.name, f.value])),
        password: '',
    });
    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                form.post(
                    company
                        ? `/platform/tenants/${company}/assets/settings`
                        : '/platform/settings/assets',
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            form.reset('password');
                            fields.filter((f) => f.secret).forEach((f) => form.reset(f.name));
                        },
                    },
                );
            }}
            className="space-y-4 rounded-xl border bg-surface p-5"
        >
            <h2 className="font-semibold">{t(title)}</h2>
            {extra && <p className="text-xs text-muted-foreground">{extra}</p>}
            <div className="grid gap-4 md:grid-cols-2">
                {fields.map((f) => (
                    <label key={f.name} className="block space-y-2 text-sm">
                        <span>{t(f.label)}</span>
                        {typeof f.value === 'boolean' ? (
                            <input
                                type="checkbox"
                                className="ml-3 size-5 align-middle"
                                checked={Boolean(form.data[f.name])}
                                onChange={(e) => form.setData(f.name, e.target.checked)}
                            />
                        ) : (
                            <Input
                                type={f.secret ? 'password' : 'text'}
                                autoComplete={f.secret ? 'new-password' : 'off'}
                                readOnly={f.readOnly}
                                value={String(form.data[f.name])}
                                onChange={(e) => form.setData(f.name, e.target.value)}
                            />
                        )}
                    </label>
                ))}
                <label className="space-y-2 text-sm">
                    <span>{t('Current password')}</span>
                    <Input
                        type="password"
                        autoComplete="current-password"
                        value={String(form.data.password)}
                        onChange={(e) => form.setData('password', e.target.value)}
                        required
                    />
                </label>
            </div>
            {Object.values(form.errors).map((m, i) => (
                <p role="alert" className="text-sm text-destructive" key={i}>
                    {errorMessage(m)}
                </p>
            ))}
            <Button disabled={form.processing}>{t('Save')}</Button>
        </form>
    );
}
