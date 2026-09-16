import type { ReactNode } from 'react';
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
        snapshot: { observed_at: string; rates: Record<string, string> } | null;
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
    advanced?: boolean;
    hidden?: boolean;
    when?: string;
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
                {!p.company ? (
                    <>
                        <ConfigForm
                            title={t('Platform exchange rates')}
                            extra={t(
                                'The platform updates rates once per minute. All users read the same saved rates; user requests never fetch external prices.',
                            )}
                            secondary={
                                p.market.enabled
                                    ? { kind: 'market-refresh', label: 'Update platform rates' }
                                    : undefined
                            }
                            after={
                                p.market.snapshot ? (
                                    <div className="space-y-2 border-t pt-3 text-sm">
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
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t(
                                            'No fresh platform rates. Enable rates and run a platform update.',
                                        )}
                                    </p>
                                )
                            }
                            kind="market"
                            fields={[
                                { name: 'enabled', label: 'Enabled', value: p.market.enabled },
                                {
                                    name: 'use_public',
                                    label: 'Use public prices (no API key)',
                                    value: !p.market.configured,
                                },
                                {
                                    name: 'api_key',
                                    advanced: true,
                                    when: '!use_public',
                                    label: p.market.configured ? 'Replace API key' : 'API key',
                                    value: '',
                                    secret: true,
                                },
                            ]}
                        />
                        <div className="space-y-2">
                            <h2 className="font-semibold">{t('External deposit verification')}</h2>
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
                                secondary={{ kind: 'network-test', label: 'Test connection' }}
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
    secondary,
    after,
}: {
    title: string;
    kind: string;
    fields: Field[];
    fixed?: Record<string, string>;
    company?: string;
    extra?: string;
    secondary?: { kind: string; label: string };
    after?: ReactNode;
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
                const submitter = e.nativeEvent.submitter as HTMLButtonElement | null;
                form.transform((data) => ({ ...data, kind: submitter?.value || kind }));
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
                {[false, true].map((advanced) => {
                    const visible = fields.filter(
                        (f) =>
                            !f.hidden &&
                            Boolean(f.advanced) === advanced &&
                            (!f.when ||
                                (f.when.startsWith('!')
                                    ? !form.data[f.when.slice(1)]
                                    : Boolean(form.data[f.when]))),
                    );
                    const controls = visible.map((f) => (
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
            <div className="flex flex-wrap gap-3">
                <Button value={kind} disabled={form.processing}>
                    {t('Save')}
                </Button>
                {secondary && (
                    <Button variant="secondary" value={secondary.kind} disabled={form.processing}>
                        {t(secondary.label)}
                    </Button>
                )}
            </div>
            {after}
        </form>
    );
}
