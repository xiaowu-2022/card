import { createContext, useContext, useState, type ReactNode } from 'react';
import { dateTime } from '@/i18n';
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
    market: {
        enabled: boolean;
        configured: boolean;
        snapshot: { observed_at: string; fresh: boolean; rates: Record<string, string> } | null;
    };
    networks: {
        network: string;
        enabled: boolean;
        rpc_url: string;
        use_public: boolean;
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
        withdrawal_fee_percent: string | null;
    }[];
    policies: {
        asset_code: string;
        enabled: boolean;
    }[];
};
type Field = {
    name: string;
    label: string;
    value: string | boolean;
    secret?: boolean;
    readOnly?: boolean;
    advanced?: boolean;
    hidden?: boolean;
    when?: string;
};
type Section = Record<string, string | boolean>;
type SettingsContextValue = {
    sections: Section[];
    update: (index: number, key: string, value: string | boolean) => void;
    run: (section: Section, action: string) => void;
    processing: boolean;
};
const SettingsContext = createContext<SettingsContextValue | null>(null);
function sectionKey(s: Section) {
    return `${s.kind}:${s.network || s.code || s.asset || ''}`;
}
function initialSections(p: Props): Section[] {
    if (p.company)
        return [
            ...p.rails.map((r) => {
                const c = p.companyRails.find((c) => c.rail_code === r.code);
                return {
                    kind: 'company-rail',
                    code: r.code,
                    deposit_enabled: c?.deposit_enabled ?? false,
                    withdrawal_enabled: c?.withdrawal_enabled ?? false,
                    minimum: trim(c?.minimum_deposit),
                    fee_percent: trim(c?.withdrawal_fee_percent),
                };
            }),
            ...['USDC', 'ETH', 'BTC'].map((asset) => {
                const c = p.policies.find((c) => c.asset_code === asset);
                return {
                    kind: 'exchange',
                    asset,
                    enabled: c?.enabled ?? false,
                };
            }),
        ];
    return [
        {
            kind: 'market',
            enabled: p.market.enabled,
            use_public: !p.market.configured,
            api_key: '',
        },
        ...p.networks.map((n) => ({
            kind: 'network',
            network: n.network,
            enabled: n.enabled,
            use_public: n.use_public,
            rpc_url: n.rpc_url,
            username: '',
            credential: '',
            start_height: n.start_height?.toString() ?? '',
            start_from_current: n.start_height === null,
            confirmations: n.confirmations.toString(),
        })),
        ...p.rails.map((r) => ({
            kind: 'rail',
            code: r.code,
            enabled: r.enabled,
            address: r.address,
        })),
    ];
}
export default function AssetSettings(p: Props) {
    return <AssetSettingsForm key={p.company ?? 'global'} {...p} />;
}
function AssetSettingsForm(p: Props) {
    useAdminTranslation();
    const initial = initialSections(p);
    const form = useForm<{ sections: Section[] }>({
        sections: initial,
    });
    const [requestSections, setRequestSections] = useState<Section[]>([]);
    const dirty = form.data.sections.filter(
        (s, i) => JSON.stringify(s) !== JSON.stringify(initial[i]),
    );
    const endpoint = p.company
        ? `/platform/tenants/${p.company}/assets/settings`
        : '/platform/settings/assets';
    const submit = (sections: Section[], action?: string) => {
        setRequestSections(sections);
        form.clearErrors();
        form.transform(() =>
            action ? { ...sections[0], kind: action } : { kind: 'batch', sections },
        );
        form.post(endpoint, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!action)
                    form.setData('sections', initialSections(page.props as unknown as Props));
            },
        });
    };
    return (
        <PlatformLayout>
            <Head title={t('Multi-currency settings')} />
            <div className="space-y-6">
                <PageHeader title={t('Multi-currency settings')} />
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Internal balances and exchange use the platform ledger. Only external deposits and withdrawals query the blockchain.',
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
                <SettingsContext.Provider
                    value={{
                        sections: form.data.sections,
                        processing: form.processing,
                        update: (index, key, value) =>
                            form.setData(
                                'sections',
                                form.data.sections.map((s, i) =>
                                    i === index ? { ...s, [key]: value } : s,
                                ),
                            ),
                        run: (section, action) => submit([section], action),
                    }}
                >
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (dirty.length) submit(dirty);
                        }}
                        className="space-y-6"
                    >
                        <div className="space-y-3 rounded-xl border bg-surface p-5">
                            <Button disabled={form.processing || !dirty.length}>
                                {t('Save all changes')}
                            </Button>
                            {Object.entries(form.errors).map(([key, message]) => {
                                const match = /^sections\.(\d+)\./.exec(key);
                                const section = match ? requestSections[Number(match[1])] : null;
                                return (
                                    <p key={key} role="alert" className="text-sm text-destructive">
                                        {section
                                            ? `${section.network || section.code || section.asset || t('Platform exchange rates')}: `
                                            : ''}
                                        {errorMessage(message)}
                                    </p>
                                );
                            })}
                        </div>
                        {!p.company ? (
                            <>
                                <ConfigForm
                                    title={t('Platform exchange rates')}
                                    extra={t(
                                        'The platform updates rates once per minute. All users read the same saved rates; user requests never fetch external prices.',
                                    )}
                                    secondary={
                                        p.market.enabled
                                            ? {
                                                  kind: 'market-refresh',
                                                  label: 'Update platform rates',
                                              }
                                            : undefined
                                    }
                                    after={
                                        p.market.snapshot ? (
                                            <div className="space-y-2 border-t pt-3 text-sm">
                                                <p className="font-medium">
                                                    {t('Last saved rates')}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {t('Updated at')}:{' '}
                                                    {dateTime(p.market.snapshot.observed_at)}
                                                </p>
                                                {Object.entries(p.market.snapshot.rates).map(
                                                    ([asset, rate]) => (
                                                        <p key={asset} className="break-words">
                                                            {`1 ${asset} = ${trim(rate)} USDT`}
                                                        </p>
                                                    ),
                                                )}
                                                <p className="text-xs text-muted-foreground">
                                                    {!p.market.enabled
                                                        ? t(
                                                              'Rate updates are disabled. Saved rates are shown for reference only.',
                                                          )
                                                        : p.market.snapshot.fresh
                                                          ? t(
                                                                'These rates are within the exchange validity period.',
                                                            )
                                                          : t(
                                                                'These rates have expired and cannot be used for exchange. Update platform rates and check the server scheduler.',
                                                            )}
                                                </p>
                                            </div>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                {t(
                                                    p.market.enabled
                                                        ? 'No rates have been saved yet. Click Update platform rates.'
                                                        : 'No rates have been saved yet. Enable and save the settings, then update platform rates.',
                                                )}
                                            </p>
                                        )
                                    }
                                    kind="market"
                                    fields={[
                                        {
                                            name: 'enabled',
                                            label: 'Enabled',
                                            value: p.market.enabled,
                                        },
                                        {
                                            name: 'use_public',
                                            label: 'Use public prices (no API key)',
                                            value: !p.market.configured,
                                        },
                                        {
                                            name: 'api_key',
                                            advanced: true,
                                            when: '!use_public',
                                            label: p.market.configured
                                                ? 'Replace API key'
                                                : 'API key',
                                            value: '',
                                            secret: true,
                                        },
                                    ]}
                                />
                                <div className="space-y-2">
                                    <h2 className="font-semibold">
                                        {t('External deposit verification')}
                                    </h2>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'Public nodes need no key. Verify the connection before enabling. SaaS can also confirm receipt of a fixed deposit order.',
                                        )}
                                    </p>
                                </div>
                                {p.networks.map((n) => (
                                    <ConfigForm
                                        key={n.network}
                                        title={
                                            n.network === 'ETHEREUM'
                                                ? 'Ethereum network'
                                                : 'Bitcoin network'
                                        }
                                        secondary={{
                                            kind: 'network-test',
                                            label: 'Test connection',
                                        }}
                                        kind="network"
                                        fixed={{ network: n.network }}
                                        fields={[
                                            { name: 'enabled', label: 'Enabled', value: n.enabled },
                                            {
                                                name: 'use_public',
                                                label: 'Use public node (no credentials)',
                                                value: n.use_public,
                                            },
                                            ...(n.start_height === null
                                                ? [
                                                      {
                                                          name: 'start_from_current',
                                                          label: 'Start from the current confirmed block (no historical scan)',
                                                          value: true,
                                                      },
                                                  ]
                                                : []),
                                            {
                                                name: 'rpc_url',
                                                advanced: true,
                                                when: '!use_public',
                                                label: 'HTTPS RPC endpoint',
                                                value: n.rpc_url,
                                            },
                                            {
                                                name: 'username',
                                                advanced: true,
                                                when: '!use_public',
                                                label: 'RPC username (optional)',
                                                value: '',
                                            },
                                            {
                                                name: 'credential',
                                                advanced: true,
                                                when: '!use_public',
                                                label: n.configured
                                                    ? 'Replace node credential'
                                                    : 'Node credential',
                                                value: '',
                                                secret: true,
                                            },
                                            {
                                                name: 'start_height',
                                                when:
                                                    n.start_height === null
                                                        ? '!start_from_current'
                                                        : undefined,
                                                label: 'Initial scan block',
                                                value: n.start_height?.toString() ?? '',
                                                readOnly: n.start_height !== null,
                                            },
                                            {
                                                name: 'confirmations',
                                                hidden: n.network !== 'BITCOIN',
                                                advanced: true,
                                                label: 'Bitcoin confirmations (minimum 6)',
                                                value: n.confirmations.toString(),
                                            },
                                        ]}
                                        extra={`${t('Next scan block')}: ${n.next_height ?? '—'} · ${t('Existing scan progress is preserved.')}`}
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
                                <h2 className="font-semibold">
                                    {t('Deposit and withdrawal networks')}
                                </h2>
                                {p.rails.map((r) => {
                                    const c = p.companyRails.find((c) => c.rail_code === r.code);
                                    return (
                                        <ConfigForm
                                            key={r.code}
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
                                                    name: 'fee_percent',
                                                    label: 'Withdrawal fee (%)',
                                                    value: trim(c?.withdrawal_fee_percent),
                                                },
                                            ]}
                                        />
                                    );
                                })}
                                <div className="space-y-2">
                                    <h2 className="font-semibold">{t('Internal exchange')}</h2>
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'Internal exchange does not require a blockchain connection or network fee.',
                                        )}
                                    </p>
                                </div>
                                {['USDC', 'ETH', 'BTC'].map((asset) => {
                                    const c = p.policies.find((c) => c.asset_code === asset);
                                    return (
                                        <ConfigForm
                                            key={asset}
                                            title={`${asset} → USDT`}
                                            kind="exchange"
                                            fixed={{ asset }}
                                            fields={[
                                                {
                                                    name: 'enabled',
                                                    label: 'Enabled',
                                                    value: c?.enabled ?? false,
                                                },
                                            ]}
                                        />
                                    );
                                })}
                            </>
                        )}
                    </form>
                </SettingsContext.Provider>
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
    extra,
    secondary,
    after,
}: {
    title: string;
    kind: string;
    fields: Field[];
    fixed?: Record<string, string>;
    extra?: string;
    secondary?: { kind: string; label: string };
    after?: ReactNode;
}) {
    const context = useContext(SettingsContext)!;
    const index = context.sections.findIndex(
        (s) => sectionKey(s) === sectionKey({ kind, ...fixed }),
    );
    const data = context.sections[index];
    if (!data) return null;
    return (
        <section className="space-y-4 rounded-xl border bg-surface p-5">
            <h2 className="font-semibold">{t(title)}</h2>
            {extra && <p className="text-xs text-muted-foreground">{extra}</p>}
            <div className="grid gap-4 md:grid-cols-2">
                {[false, true].map((advanced) => {
                    const visible = fields.filter(
                        (f) =>
                            !f.hidden &&
                            Boolean(f.advanced) === advanced &&
                            (!f.when ||
                                (f.when.startsWith('!')
                                    ? !data[f.when.slice(1)]
                                    : Boolean(data[f.when]))),
                    );
                    const controls = visible.map((f) => (
                        <label key={f.name} className="block space-y-2 text-sm">
                            <span>{t(f.label)}</span>
                            {typeof f.value === 'boolean' ? (
                                <input
                                    type="checkbox"
                                    className="ml-3 size-5 align-middle"
                                    checked={Boolean(data[f.name])}
                                    onChange={(e) =>
                                        context.update(index, f.name, e.target.checked)
                                    }
                                />
                            ) : (
                                <Input
                                    type={f.secret ? 'password' : 'text'}
                                    autoComplete={f.secret ? 'new-password' : 'off'}
                                    readOnly={f.readOnly}
                                    value={String(data[f.name])}
                                    onChange={(e) => context.update(index, f.name, e.target.value)}
                                />
                            )}
                        </label>
                    ));
                    return advanced
                        ? visible.length > 0 && (
                              <details key="advanced" className="md:col-span-2">
                                  <summary className="cursor-pointer text-sm text-muted-foreground">
                                      {t('Advanced settings')}
                                  </summary>
                                  <div className="mt-4 grid gap-4 md:grid-cols-2">{controls}</div>
                              </details>
                          )
                        : controls;
                })}
            </div>
            {secondary && (
                <Button
                    type="button"
                    variant="secondary"
                    disabled={context.processing}
                    onClick={() => context.run(data, secondary.kind)}
                >
                    {t(secondary.label)}
                </Button>
            )}
            {after}
        </section>
    );
}
