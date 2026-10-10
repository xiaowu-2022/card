import { adminAssetLabel } from '@/lib/admin-asset-label';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { displayMoney } from '@/lib/admin-amount';
import { useForm } from '@/components/admin/editor-context';
import { Head, Link } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';

type Props = {
    account: {
        id: string;
        companyId: string;
        companyName: string;
        accountId: string;
        email: string;
    };
    balances: { asset: string; amount: string }[];
    history: AccountPage<{
        id: string;
        asset: string;
        direction: string;
        amount: string;
        before: string;
        after: string;
        reason: string;
        actor: string;
        time: string;
    }>;
};
export default function WalletAdjustment({ account, balances, history }: Props) {
    useAdminTranslation();
    const url = `/platform/tenants/${account.companyId}/users/${account.id}/wallet-adjustments`;
    const form = useForm({
        asset: balances[0]?.asset ?? '',
        direction: 'INCREASE',
        amount: '',
        reason: '',
        request_id: crypto.randomUUID(),
        confirmed: false,
    });
    const change = (key: 'asset' | 'direction' | 'amount' | 'reason', value: string) =>
        form.setData({
            ...form.data,
            [key]: value,
            confirmed: false,
            request_id: crypto.randomUUID(),
        });
    return (
        <PlatformLayout
            title={t('Wallet adjustment')}
            description={`${account.companyName} · ${account.accountId} · ${account.email}`}
        >
            <Head title={t('Wallet adjustment')} />
            <div className="space-y-4">
                <Link
                    className="text-primary underline"
                    href={`/platform/users?company=${account.companyId}`}
                >
                    {t('Back to users')}
                </Link>
                <form
                    className="max-w-2xl space-y-4 rounded-xl border bg-surface p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url, {
                            preserveScroll: true,
                            onSuccess: () =>
                                form.setData({
                                    ...form.data,
                                    amount: '',
                                    reason: '',
                                    confirmed: false,
                                    request_id: crypto.randomUUID(),
                                }),
                        });
                    }}
                >
                    <p>
                        {t(
                            'Adjust available balance. A decrease cannot exceed the available balance.',
                        )}
                    </p>
                    <fieldset disabled={form.processing || !balances.length} className="space-y-4">
                        <label className="block space-y-2">
                            <span>{t('Currency')}</span>
                            <select
                                className="block h-10 w-full rounded-md border bg-surface px-3"
                                value={form.data.asset}
                                onChange={(e) => change('asset', e.target.value)}
                            >
                                {balances.map((b) => (
                                    <option key={b.asset} value={b.asset}>
                                        {adminAssetLabel(b.asset)} · {t('Available balance')}:{' '}
                                        {displayMoney(b.amount)}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block space-y-2">
                            <span>{t('Adjustment direction')}</span>
                            <select
                                className="block h-10 w-full rounded-md border bg-surface px-3"
                                value={form.data.direction}
                                onChange={(e) => change('direction', e.target.value)}
                            >
                                <option value="INCREASE">{t('Increase balance')}</option>
                                <option value="DECREASE">{t('Decrease balance')}</option>
                            </select>
                        </label>
                        <label className="block space-y-2">
                            <span>{t('Adjustment amount')}</span>
                            <input
                                required
                                inputMode="decimal"
                                className="block h-10 w-full rounded-md border bg-surface px-3"
                                value={form.data.amount}
                                onChange={(e) => change('amount', e.target.value)}
                            />
                        </label>
                        <label className="block space-y-2">
                            <span>{t('Adjustment reason')}</span>
                            <textarea
                                required
                                maxLength={500}
                                className="block min-h-24 w-full rounded-md border bg-surface p-3"
                                value={form.data.reason}
                                onChange={(e) => change('reason', e.target.value)}
                            />
                        </label>
                        <div className="rounded-md border p-3">
                            <p>
                                {account.accountId} ·{' '}
                                {t(
                                    form.data.direction === 'INCREASE'
                                        ? 'Increase balance'
                                        : 'Decrease balance',
                                )}{' '}
                                ·{' '}
                                <strong>
                                    {displayMoney(form.data.amount || '0')}{' '}
                                    {adminAssetLabel(form.data.asset)}
                                </strong>
                            </p>
                            <label className="mt-3 flex items-center gap-2">
                                <input
                                    required
                                    type="checkbox"
                                    checked={form.data.confirmed}
                                    onChange={(e) => form.setData('confirmed', e.target.checked)}
                                />
                                {t(
                                    'I confirm this adjustment changes the customer’s available balance immediately.',
                                )}
                            </label>
                        </div>
                        {Object.entries(form.errors).map(([key, value]) => (
                            <OperationFeedback key={key} role="alert" className="text-destructive">
                                {t(value)}
                            </OperationFeedback>
                        ))}
                        <Button type="submit" disabled={form.processing || !form.data.confirmed}>
                            {t('Confirm adjustment')}
                        </Button>
                    </fieldset>
                    {!balances.length && <p>{t('This customer has no wallet to adjust.')}</p>}
                </form>
                <PlatformAccountTable
                    url={url}
                    filters={{}}
                    searchLabel=""
                    showFilters={false}
                    page={history}
                    columns={[
                        { label: 'Type', render: () => t('Admin adjustment') },
                        { label: 'Currency', render: (r) => adminAssetLabel(r.asset) },
                        {
                            label: 'Adjustment amount',
                            render: (r) =>
                                `${r.direction === 'INCREASE' ? '+' : '-'}${displayMoney(r.amount)}`,
                        },
                        { label: 'Balance before', render: (r) => displayMoney(r.before) },
                        { label: 'Balance after', render: (r) => displayMoney(r.after) },
                        { label: 'Adjustment reason', render: (r) => r.reason },
                        { label: 'Operator', render: (r) => r.actor },
                        { label: 'Time', render: (r) => dateTime(r.time) },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
