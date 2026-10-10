import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { displayMoney } from '@/lib/admin-amount';
import { useForm, useEditor } from '@/components/admin/editor-context';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { t, useAdminTranslation, dateTime } from '@/i18n/admin';

type Kind = 'activation' | 'annual' | 'legacy';
const names: Record<Kind, string> = {
    activation: 'Activation commission',
    annual: 'Annual fee commission',
    legacy: 'Legacy commission',
};
const units = (s: string) => {
    const negative = s.startsWith('-');
    const [i, f = ''] = s.replace(/^[+-]/, '').split('.');
    return (BigInt(i || '0') * 100000000n + BigInt(f.padEnd(8, '0'))) * (negative ? -1n : 1n);
};
const amount = (n: bigint) => {
    const v = (n < 0n ? -n : n).toString().padStart(9, '0');
    return (n < 0n ? '-' : '') + v.slice(0, -8) + '.' + v.slice(-8);
};
type Row = {
    id: string;
    kind: Kind | null;
    direction: string;
    amount: string;
    before: string;
    after: string;
    kindBefore: string | null;
    kindAfter: string | null;
    reason: string;
    actor: string;
    time: string;
    classifier: string | null;
    classifiedAt: string | null;
    classificationReason: string | null;
};
export default function ManualCommission({
    account,
    balances,
    commission,
    categories,
    history,
    filters = {},
}: {
    account: {
        id: string;
        companyId: string;
        companyName: string;
        accountId: string;
        email: string;
    };
    balances: { asset: string; amount: string }[];
    commission: string;
    categories: Record<Kind, string>;
    history: AccountPage<Row>;
    filters?: { kind?: string };
}) {
    useAdminTranslation();
    const editor = useEditor();
    const url = `/platform/tenants/${account.companyId}/users/${account.id}/manual-commissions`;
    const [classifying, setClassifying] = useState<Row | null>(null);
    const form = useForm({
        commission_type: 'activation' as Kind,
        signed: '',
        reason: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
    const delta = /^[+-]?(?:0|[1-9]\d{0,11})(?:\.\d{1,8})?$/.test(form.data.signed)
        ? units(form.data.signed)
        : 0n;
    const adjustment = classifying
        ? units(classifying.amount) * (classifying.direction === 'DECREASE' ? -1n : 1n)
        : delta;
    const balance = units(balances[0]?.amount ?? '0'),
        total = units(commission),
        category = units(categories[form.data.commission_type]);
    const limit = [balance, total, category].reduce((a, b) => (a < b ? a : b));
    const valid =
        adjustment !== 0n &&
        category + adjustment >= 0n &&
        (classifying ||
            (balances.length && balance + adjustment >= 0n && total + adjustment >= 0n));
    function change(key: 'commission_type' | 'signed' | 'reason', value: string) {
        form.setData((current) => ({
            ...current,
            [key]: value,
            confirmed: false,
            request_id: crypto.randomUUID(),
        }));
    }
    return (
        <PlatformLayout
            title={t('Adjust commission')}
            description={`${account.companyName} · ${account.accountId} · ${account.email}`}
        >
            <div className="space-y-4">
                <Head title={t('Adjust commission')} />

                <dl className="grid grid-cols-2 gap-3 rounded border p-4 lg:grid-cols-3">
                    {[
                        ...Object.entries(categories).map(([key, value]) => [
                            names[key as Kind],
                            value,
                        ]),
                        ['Cumulative net commission', commission],
                        ['Available balance', amount(balance)],
                        ['Maximum deduction', amount(limit)],
                    ].map(([label, value]) => (
                        <div key={label}>
                            <dt className="text-sm text-muted-foreground">{t(label!)}</dt>
                            <dd>{displayMoney(value!)} USDT</dd>
                        </div>
                    ))}
                </dl>
                <form
                    className="space-y-4 rounded border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (!valid || form.processing) return;
                        form.transform((data) => ({
                            commission_type: data.commission_type,
                            asset: 'USDT',
                            direction: delta < 0n ? 'DECREASE' : 'INCREASE',
                            amount: amount(delta < 0n ? -delta : delta),
                            reason: data.reason,
                            confirmed: data.confirmed,
                            request_id: data.request_id,
                        }));
                        form.post(classifying ? `${url}/${classifying.id}/classify` : url, {
                            preserveScroll: true,
                            onSuccess: () => {
                                form.reset();
                                form.setData('request_id', crypto.randomUUID());
                                setClassifying(null);
                            },
                        });
                    }}
                >
                    {classifying && (
                        <p className="rounded bg-muted p-3">
                            {t('Classify historical commission. No funds will move.')}{' '}
                            {classifying.direction === 'DECREASE' ? '−' : '+'}
                            {displayMoney(classifying.amount)} USDT{' '}
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => {
                                    setClassifying(null);
                                    form.reset();
                                }}
                            >
                                {t('Cancel')}
                            </Button>
                        </p>
                    )}
                    <label className="block">
                        {t('Commission type')}
                        <select
                            className="mt-1 h-10 w-full rounded border bg-surface px-3"
                            value={form.data.commission_type}
                            onChange={(e) => change('commission_type', e.target.value)}
                        >
                            {Object.entries(names).map(([key, label]) => (
                                <option key={key} value={key}>
                                    {t(label)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {!classifying && (
                        <label className="block">
                            {t('Signed adjustment amount')}
                            <input
                                required
                                className="mt-1 h-10 w-full rounded border px-3"
                                inputMode="text"
                                placeholder="+100 / -50"
                                value={form.data.signed}
                                onChange={(e) => change('signed', e.target.value)}
                            />
                        </label>
                    )}
                    <label className="block">
                        {t('Adjustment reason')}
                        <textarea
                            required
                            maxLength={500}
                            className="mt-1 min-h-20 w-full rounded border p-3"
                            value={form.data.reason}
                            onChange={(e) => change('reason', e.target.value)}
                        />
                    </label>
                    <div className="rounded bg-muted p-3 text-sm">
                        <p>
                            {t('Expected category balance')}:{' '}
                            {displayMoney(amount(category + adjustment))} USDT
                        </p>
                        {!classifying && (
                            <p>
                                {t('Expected available balance')}:{' '}
                                {displayMoney(amount(balance + delta))} USDT {' · '}
                                {t('Expected cumulative net commission')}:{' '}
                                {displayMoney(amount(total + delta))} USDT
                            </p>
                        )}
                    </div>
                    <label className="flex gap-2">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.confirmed}
                            onChange={(e) => form.setData('confirmed', e.target.checked)}
                        />
                        {t(
                            classifying
                                ? 'I confirm this one-time category assignment without moving funds.'
                                : 'I confirm this adjustment changes the customer’s available balance immediately.',
                        )}
                    </label>
                    {Object.entries(form.errors).map(([key, value]) => (
                        <OperationFeedback role="alert" className="text-destructive" key={key}>
                            {t(value)}
                        </OperationFeedback>
                    ))}
                    <Button disabled={!valid || !form.data.confirmed || form.processing}>
                        {t(classifying ? 'Confirm classification' : 'Confirm adjustment')}
                    </Button>
                </form>
                <h2 className="font-semibold">{t('Adjustment history')}</h2>
                <select
                    aria-label={t('Commission type')}
                    className="rounded border bg-surface p-2"
                    value={filters.kind ?? 'all'}
                    onChange={(e) => {
                        const next =
                            url + (e.target.value === 'all' ? '' : '?kind=' + e.target.value);
                        if (editor) editor.navigate(next);
                        else router.get(next);
                    }}
                >
                    <option value="all">{t('All types')}</option>
                    {Object.entries(names).map(([key, label]) => (
                        <option key={key} value={key}>
                            {t(label)}
                        </option>
                    ))}
                    <option value="pending">{t('Awaiting classification')}</option>
                </select>
                <PlatformAccountTable
                    page={history}
                    filters={{}}
                    url={url}
                    searchLabel=""
                    showFilters={false}
                    columns={[
                        {
                            label: 'Commission type',
                            render: (row) => (
                                <div>
                                    {t(row.kind ? names[row.kind] : 'Awaiting classification')}
                                    <div className="text-xs text-muted-foreground">
                                        {t('Backend adjustment')}
                                    </div>
                                </div>
                            ),
                        },
                        {
                            label: 'Amount',
                            render: (row) =>
                                `${row.direction === 'DECREASE' ? '-' : '+'}${displayMoney(row.amount)} USDT`,
                        },
                        {
                            label: 'Category balance',
                            render: (row) =>
                                row.kindBefore === null
                                    ? '—'
                                    : `${displayMoney(row.kindBefore)} → ${displayMoney(row.kindAfter!)}`,
                        },
                        {
                            label: 'Available balance',
                            render: (row) =>
                                `${displayMoney(row.before)} → ${displayMoney(row.after)}`,
                        },
                        {
                            label: 'Operator',
                            render: (row) => (
                                <div>
                                    {row.actor}
                                    <div className="text-xs">{dateTime(row.time)}</div>
                                    {row.classifier && (
                                        <div className="text-xs">
                                            {t('Classified by')}: {row.classifier} ·{' '}
                                            {dateTime(row.classifiedAt!)}
                                        </div>
                                    )}
                                </div>
                            ),
                        },
                        {
                            label: 'Reason',
                            render: (row) => (
                                <div>
                                    {row.reason}
                                    {row.classificationReason &&
                                        row.classificationReason !== row.reason && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {t('Classification reason')}:{' '}
                                                {row.classificationReason}
                                            </p>
                                        )}
                                </div>
                            ),
                        },
                        {
                            label: 'Actions',
                            render: (row) =>
                                !row.kind ? (
                                    <Button
                                        size="sm"
                                        onClick={() => {
                                            setClassifying(row);
                                            form.reset();
                                            form.setData('request_id', crypto.randomUUID());
                                        }}
                                    >
                                        {t('Assign commission type')}
                                    </Button>
                                ) : (
                                    '—'
                                ),
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
