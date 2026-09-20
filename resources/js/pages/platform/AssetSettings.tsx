import { AssetNavigation } from '@/components/admin/AssetNavigation';
import { createContext, useContext, useState, type ReactNode } from 'react';
import { dateTime } from '@/i18n';
import { Head, router, useForm } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, useAdminTranslation, errorMessage } from '@/i18n/admin';

type Rail = { code: string; asset: string; network: string; address: string; enabled: boolean };
type Props = {
    tronAddress: string;
    tronFeePercent: string | null;
    tronMinimum: string | null;
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
    errorsFor: (section: Section) => string[];
};
const SettingsContext = createContext<SettingsContextValue | null>(null);
function sectionKey(s: Section) {
    return `${s.kind}:${s.network || s.code || s.asset || ''}`;
}
function initialSections(p: Props): Section[] {
    if (p.company)
        return [
            {
                kind: 'company-tron',
                minimum: trim(p.tronMinimum),
                fee_percent: trim(p.tronFeePercent),
            },
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
        { kind: 'tron-rail', address: p.tronAddress },
        {
            kind: 'market',
            enabled: true,
            use_public: true,
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
            <div className="mx-auto max-w-6xl space-y-6">
                <PageHeader
                    title={t('Multi-currency settings')}
                    description={t(
                        'Manage receiving addresses and company deposit, withdrawal and exchange settings.',
                    )}
                />
                <AssetNavigation active="settings" />
                <SettingsContext.Provider
                    value={{
                        sections: form.data.sections,
                        processing: form.processing,
                        errorsFor: (section) =>
                            Object.entries(form.errors)
                                .filter(([key]) => {
                                    const match = /^sections\.(\d+)\./.exec(key);
                                    const requested = match
                                        ? requestSections[Number(match[1])]
                                        : null;
                                    return (
                                        requested && sectionKey(requested) === sectionKey(section)
                                    );
                                })
                                .map(([, message]) => errorMessage(message) ?? message),
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
                        className="space-y-3"
                    >
                        <div className="sticky top-3 z-20 space-y-3 rounded-xl border bg-surface p-5 shadow-sm">
                            <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                                <label className="block w-full space-y-2 text-sm sm:max-w-sm">
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
                                <Button type="submit" disabled={form.processing || !dirty.length}>
                                    {t('Save all changes')}
                                </Button>
                            </div>
                            {Object.keys(form.errors).length > 0 && (
                                <p role="alert" className="font-semibold text-destructive">
                                    {t(
                                        'Save failed. No changes were saved. Your entries remain below; correct the errors and save again.',
                                    )}
                                </p>
                            )}
                            {Object.entries(form.errors).map(([key, message]) => {
                                const match = /^sections\.(\d+)\./.exec(key);
                                const section = match ? requestSections[Number(match[1])] : null;
                                return (
                                    <p key={key} role="alert" className="text-sm text-destructive">
                                        {section
                                            ? `${section.kind === 'tron-rail' || section.kind === 'company-tron' ? 'USDT / TRON' : section.network || section.code || section.asset || t('Platform exchange rates')}: `
                                            : ''}
                                        {errorMessage(message)}
                                    </p>
                                );
                            })}
                        </div>
                        {!p.company ? (
                            <>
                                <section className="space-y-3 rounded-xl border bg-surface p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <h2 className="text-sm font-semibold">
                                                {t('Latest exchange quote')}
                                            </h2>
                                            {p.market.snapshot && (
                                                <p className="mt-1 text-xs text-muted-foreground">
                                                    {t('Updated at')}:{' '}
                                                    {dateTime(p.market.snapshot.observed_at)}
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <p className="text-xs text-muted-foreground">
                                        {t(
                                            'New exchange quotes fetch live OKX rates. Saved rates below are for reference only.',
                                        )}
                                    </p>
                                    {p.market.snapshot ? (
                                        <div className="grid gap-2 sm:grid-cols-3">
                                            {Object.entries(p.market.snapshot.rates).map(
                                                ([asset, rate]) => (
                                                    <div
                                                        key={asset}
                                                        className="min-w-0 rounded-lg bg-muted/50 px-3 py-2 text-sm"
                                                    >
                                                        <p className="text-xs text-muted-foreground">{`1 ${asset}`}</p>
                                                        <p className="mt-1 break-all font-medium tabular-nums">
                                                            {trim(rate)}{' '}
                                                            <span className="text-xs font-normal text-muted-foreground">
                                                                USDT
                                                            </span>
                                                        </p>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            {t('No exchange quotes yet.')}
                                        </p>
                                    )}
                                </section>
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'Direct ETH and ERC20 transfers are checked automatically. Deposits that cannot be verified automatically require manual receipt confirmation in deposit orders.',
                                    )}
                                </p>
                                <div className="divide-y overflow-hidden rounded-xl border bg-surface">
                                    <ConfigForm
                                        title={['USDT', 'TRON (TRC20)'].join(' · ')}
                                        kind="tron-rail"
                                        fields={[
                                            {
                                                name: 'address',
                                                label: 'Receiving address',
                                                value: p.tronAddress,
                                            },
                                        ]}
                                    />
                                    {p.rails.map((r) => (
                                        <ConfigForm
                                            key={r.code}
                                            title={`${r.asset} · ${r.network === 'ETHEREUM' ? (r.asset === 'ETH' ? 'Ethereum' : 'ERC20') : 'Bitcoin'}`}
                                            kind="rail"
                                            fixed={{ code: r.code }}
                                            fields={[
                                                {
                                                    name: 'enabled',
                                                    label: 'Enabled',
                                                    value: r.enabled,
                                                },
                                                {
                                                    name: 'address',
                                                    label: 'Receiving address',
                                                    value: r.address,
                                                },
                                            ]}
                                        />
                                    ))}
                                </div>
                            </>
                        ) : (
                            <>
                                <h2 className="font-semibold">
                                    {t('Deposit and withdrawal networks')}
                                </h2>
                                <ConfigForm
                                    title={['USDT', 'TRON (TRC20)'].join(' · ')}
                                    kind="company-tron"
                                    fields={[
                                        {
                                            name: 'minimum',
                                            label: 'Minimum deposit',
                                            value: trim(p.tronMinimum),
                                        },
                                        {
                                            name: 'fee_percent',
                                            label: 'Withdrawal fee (%)',
                                            value: trim(p.tronFeePercent),
                                        },
                                    ]}
                                />
                                {p.rails.map((r) => {
                                    const c = p.companyRails.find((c) => c.rail_code === r.code);
                                    return (
                                        <ConfigForm
                                            key={r.code}
                                            title={`${r.asset} · ${r.network === 'ETHEREUM' ? (r.asset === 'ETH' ? 'Ethereum' : 'ERC20') : 'Bitcoin'}`}
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
                                <div className="grid gap-3 md:grid-cols-3">
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
                                </div>
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
    if (kind === 'company-tron' || kind === 'company-rail' || kind === 'exchange') {
        return (
            <section
                className={
                    kind === 'exchange'
                        ? 'flex items-center justify-between gap-3 rounded-lg border bg-surface px-4 py-3'
                        : 'grid items-center gap-3 rounded-lg border bg-surface px-4 py-3 lg:grid-cols-[11rem_minmax(0,1fr)]'
                }
            >
                <h2 className="text-sm font-semibold">{t(title)}</h2>
                <div className="flex flex-wrap items-center gap-x-5 gap-y-2">
                    {fields.map((field) => (
                        <label
                            key={field.name}
                            className={
                                typeof field.value === 'boolean'
                                    ? 'flex items-center gap-2 text-sm'
                                    : 'min-w-32 flex-1 space-y-1 text-xs text-muted-foreground'
                            }
                        >
                            {typeof field.value === 'boolean' ? (
                                <>
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-primary"
                                        disabled={context.processing}
                                        checked={Boolean(data[field.name])}
                                        onChange={(event) =>
                                            context.update(index, field.name, event.target.checked)
                                        }
                                    />
                                    <span>{t(field.label)}</span>
                                </>
                            ) : (
                                <>
                                    <span>{t(field.label)}</span>
                                    <Input
                                        inputMode="decimal"
                                        className="h-9"
                                        disabled={context.processing}
                                        value={String(data[field.name])}
                                        onChange={(event) =>
                                            context.update(index, field.name, event.target.value)
                                        }
                                    />
                                </>
                            )}
                        </label>
                    ))}
                </div>
                {context.errorsFor(data).map((message, i) => (
                    <p key={i} role="alert" className="text-sm text-destructive lg:col-span-2">
                        {message}
                    </p>
                ))}
            </section>
        );
    }
    if (kind === 'rail' || kind === 'tron-rail') {
        return (
            <section className="grid min-w-0 grid-cols-[minmax(0,1fr)_auto] items-center gap-x-4 gap-y-3 p-4 lg:grid-cols-[11rem_6rem_minmax(0,1fr)]">
                <h2 className="min-w-0 text-sm font-semibold">{t(title)}</h2>
                {kind === 'rail' ? (
                    <label className="flex min-h-10 items-center gap-2 text-sm text-muted-foreground">
                        <input
                            type="checkbox"
                            className="size-4 accent-primary"
                            checked={Boolean(data.enabled)}
                            disabled={context.processing}
                            onChange={(e) => context.update(index, 'enabled', e.target.checked)}
                        />
                        {t('Enabled')}
                    </label>
                ) : (
                    <span className="text-right text-xs text-muted-foreground lg:text-left">
                        {t('Enabled')}
                    </span>
                )}
                <label className="col-span-2 min-w-0 lg:col-span-1">
                    <span className="sr-only">{t('Receiving address')}</span>
                    <Input
                        autoComplete="off"
                        spellCheck={false}
                        aria-label={`${title} · ${t('Receiving address')}`}
                        placeholder={t('Receiving address')}
                        className="w-full min-w-0 font-mono text-sm"
                        readOnly={fields.find((field) => field.name === 'address')?.readOnly}
                        value={String(data.address)}
                        disabled={context.processing}
                        onChange={(e) => context.update(index, 'address', e.target.value)}
                    />
                </label>
                {extra && (
                    <p className="col-span-2 text-xs text-muted-foreground lg:col-span-3">
                        {extra}
                    </p>
                )}
                {context.errorsFor(data).map((message, i) => (
                    <p
                        key={i}
                        role="alert"
                        className="col-span-2 text-sm text-destructive lg:col-span-3"
                    >
                        {message}
                    </p>
                ))}
            </section>
        );
    }
    return (
        <section className="space-y-4 rounded-xl border bg-surface p-5">
            <h2 className="font-semibold">{t(title)}</h2>
            {context.errorsFor(data).map((message, i) => (
                <p key={i} role="alert" className="text-sm text-destructive">
                    {message}
                </p>
            ))}
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
