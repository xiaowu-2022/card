import { Head, Link } from '@inertiajs/react';
import { useForm } from '@/components/admin/editor-context';
import { useEffect } from 'react';
import { ArrowRight } from 'lucide-react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
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
    currentRank: number;
    paidRank: number;
    manualLevel: boolean;
    latestAdjustmentId: string | null;
    canAdjust: boolean;
    levels: { id: string; rank: number; enabled: boolean }[];
    history: AccountPage<{
        id: string;
        beforeRank: number;
        afterRank: number;
        manual: boolean;
        actor: string;
        reason: string;
        time: string;
    }>;
};
const level = (rank: number) =>
    rank ? t('Mastercard level {{rank}}', { rank }) : t('Ordinary member');
export default function UserPromotion(p: Props) {
    useAdminTranslation();
    const url = `/platform/tenants/${p.account.companyId}/users/${p.account.id}/promotion`;
    const form = useForm({
        choice: '',
        reason: '',
        request_id: crypto.randomUUID(),
        expected_adjustment_id: p.latestAdjustmentId,
        confirmed: false,
    });
    useEffect(() => {
        const defaults = {
            choice: '',
            reason: '',
            request_id: crypto.randomUUID(),
            expected_adjustment_id: p.latestAdjustmentId,
            confirmed: false,
        };
        form.setData(defaults);
        form.setDefaults(defaults);
    }, [p.latestAdjustmentId]);
    const nextRank =
        form.data.choice === 'paid'
            ? p.paidRank
            : (p.levels.find((l) => l.id === form.data.choice)?.rank ?? 0);
    return (
        <PlatformLayout>
            <Head title={t('Agent level')} />
            <div className="space-y-6">
                <PageHeader
                    title={t('Agent level')}
                    description={`${p.account.companyName} · ${p.account.accountId}`}
                />
                <Link
                    className="inline-block text-primary underline"
                    href={`/platform/users?company=${p.account.companyId}`}
                >
                    {t('Back to users')}
                </Link>
                <section className="space-y-2 rounded-xl border bg-surface p-5">
                    <p className="break-all">{p.account.email}</p>
                    <p>
                        {t('Current level')}: <strong>{level(p.currentRank)}</strong>
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            p.manualLevel
                                ? 'Manual level: valid until the next adjustment.'
                                : 'Level follows the paid membership period.',
                        )}
                    </p>
                </section>
                {p.canAdjust && (
                    <form
                        className="max-w-2xl space-y-4 rounded-xl border bg-surface p-5"
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(url, { preserveScroll: true });
                        }}
                    >
                        <h2 className="font-semibold">{t('Adjust promotion level')}</h2>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'No payment is collected. Future qualification and commissions follow the selected level. Existing payments and rewards stay unchanged.',
                            )}
                        </p>
                        <label className="block space-y-2">
                            <span>{t('New level')}</span>
                            <select
                                required
                                className="block h-10 w-full rounded-md border bg-surface px-3"
                                value={form.data.choice}
                                disabled={form.processing}
                                onChange={(e) =>
                                    form.setData({
                                        ...form.data,
                                        choice: e.target.value,
                                        confirmed: false,
                                        request_id: crypto.randomUUID(),
                                    })
                                }
                            >
                                <option value="">{t('Select level')}</option>
                                <option value="ordinary">{level(0)}</option>
                                {p.levels.map((l) => (
                                    <option key={l.id} value={l.id}>
                                        {level(l.rank)}
                                    </option>
                                ))}
                                <option value="paid">
                                    {t('Restore paid membership rules')} · {level(p.paidRank)}
                                </option>
                            </select>
                        </label>
                        <label className="block space-y-2">
                            <span>{t('Adjustment reason')}</span>
                            <textarea
                                required
                                maxLength={500}
                                rows={3}
                                className="block w-full rounded-md border bg-surface p-3"
                                value={form.data.reason}
                                disabled={form.processing}
                                onChange={(e) =>
                                    form.setData({
                                        ...form.data,
                                        reason: e.target.value,
                                        confirmed: false,
                                        request_id: crypto.randomUUID(),
                                    })
                                }
                            />
                        </label>
                        {form.data.choice && (
                            <div className="space-y-2 rounded-lg bg-muted p-4">
                                <p>
                                    {p.account.companyName} · {p.account.accountId}
                                </p>
                                <p>
                                    {level(p.currentRank)}{' '}
                                    <ArrowRight aria-hidden="true" className="mx-2 inline size-4" />{' '}
                                    {level(nextRank)}
                                </p>
                                <p className="whitespace-pre-wrap break-words">
                                    {form.data.reason}
                                </p>
                                <p className="text-sm">
                                    {t(
                                        form.data.choice === 'paid'
                                            ? 'Level follows the paid membership period.'
                                            : 'Manual level: valid until the next adjustment.',
                                    )}
                                </p>
                            </div>
                        )}
                        <label className="flex items-start gap-2">
                            <input
                                type="checkbox"
                                className="mt-1"
                                checked={form.data.confirmed}
                                disabled={form.processing}
                                onChange={(e) => form.setData('confirmed', e.target.checked)}
                            />
                            <span>{t('I confirm the account and level adjustment.')}</span>
                        </label>
                        {Object.values(form.errors).map((error, i) => (
                            <p key={i} role="alert" className="text-sm text-red-600">
                                {t(error)}
                            </p>
                        ))}
                        <Button
                            disabled={
                                form.processing ||
                                !form.data.choice ||
                                !form.data.reason.trim() ||
                                !form.data.confirmed
                            }
                        >
                            {t('Confirm adjustment')}
                        </Button>
                    </form>
                )}
                <h2 className="font-semibold">{t('Adjustment history')}</h2>
                <PlatformAccountTable
                    showFilters={false}
                    page={p.history}
                    url={url}
                    filters={{}}
                    searchLabel={t('Adjustment history')}
                    columns={[
                        { label: 'Before adjustment', render: (r) => level(r.beforeRank) },
                        { label: 'After adjustment', render: (r) => level(r.afterRank) },
                        {
                            label: 'Mode',
                            render: (r) => t(r.manual ? 'Manual level' : 'Paid membership'),
                        },
                        { label: 'Operator', render: (r) => r.actor },
                        { label: 'Time', render: (r) => dateTime(r.time) },
                        {
                            label: 'Adjustment reason',
                            className: 'max-w-xs whitespace-normal break-words',
                            render: (r) => r.reason,
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
