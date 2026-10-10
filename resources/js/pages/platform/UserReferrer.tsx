import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useEditor } from '@/components/admin/editor-context';
import { useForm } from '@/components/admin/editor-context';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
type Member = { id: string; account_id: string; email: string };
type Props = {
    account: {
        id: string;
        companyId: string;
        companyName: string;
        email: string;
        accountId: string;
    };
    current: Member | null;
    revision: number;
    descendants: number;
    search: string;
    candidates: Member[];
    history: AccountPage<{
        id: string;
        old_account: string | null;
        new_account: string;
        reason: string;
        actor_name: string;
        created_at: string;
        descendants: number;
    }>;
};
export default function UserReferrer({
    account,
    current,
    revision,
    descendants,
    search,
    candidates,
    history,
}: Props) {
    useAdminTranslation();
    const editor = useEditor();
    const url = `/platform/tenants/${account.companyId}/users/${account.id}/referrer`;
    const [query, setQuery] = useState(search);
    const form = useForm({
        new_inviter_id: '',
        old_inviter_id: current?.id ?? null,
        revision,
        reason: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
    const target = candidates.find((c) => c.id === form.data.new_inviter_id);
    return (
        <PlatformLayout
            title={t('Change referrer')}
            description={`${account.companyName} · ${account.accountId}`}
        >
            <Head title={t('Change referrer')} />
            <div className="space-y-4">
                <Link className="underline" href={`/platform/users?company=${account.companyId}`}>
                    {t('Back to users')}
                </Link>
                <p>
                    {t('Current referrer')}: {current?.account_id ?? '—'} ·{' '}
                    {t('Descendants moving with this user')}: {descendants}
                </p>
                <p>
                    {t(
                        'Existing commissions and activation snapshots remain unchanged. Future business uses the new relationship.',
                    )}
                </p>
                <form
                    className="flex gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        if (editor) {
                            const next = new URL(url, location.origin);
                            next.searchParams.set('search', query);
                            editor.navigate(next.pathname + next.search);
                        } else router.get(url, { search: query }, { preserveState: false });
                    }}
                >
                    <input
                        className="h-10 rounded border px-3"
                        aria-label={t('Search account or email')}
                        placeholder={t('Search account or email')}
                        value={query}
                        onChange={(e) => setQuery(e.target.value)}
                        maxLength={255}
                    />
                    <Button>{t('Search')}</Button>
                </form>
                <form
                    className="max-w-2xl space-y-4 rounded border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url, { preserveState: false });
                    }}
                >
                    <fieldset className="space-y-4" disabled={form.processing}>
                        <label className="block">
                            {t('New referrer')}
                            <select
                                required
                                className="block h-10 w-full rounded border px-3"
                                value={form.data.new_inviter_id}
                                onChange={(e) =>
                                    form.setData({
                                        ...form.data,
                                        new_inviter_id: e.target.value,
                                        confirmed: false,
                                        request_id: crypto.randomUUID(),
                                    })
                                }
                            >
                                <option value="">{t('Select a matching account')}</option>
                                {candidates.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.account_id} · {c.email}
                                    </option>
                                ))}
                            </select>
                        </label>
                        <label className="block">
                            {t('Adjustment reason')}
                            <textarea
                                required
                                maxLength={500}
                                className="block min-h-24 w-full rounded border p-3"
                                value={form.data.reason}
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
                        <p>
                            {current?.account_id ?? '—'} {'→'} {target?.account_id ?? '—'} ·{' '}
                            {t('Descendants moving with this user')}: {descendants}
                        </p>
                        <label className="flex gap-2">
                            <input
                                type="checkbox"
                                required
                                checked={form.data.confirmed}
                                onChange={(e) => form.setData('confirmed', e.target.checked)}
                            />
                            {t(
                                'I confirm the referrer change for this user and their descendants.',
                            )}
                        </label>
                        {Object.entries(form.errors).map(([key, value]) => (
                            <OperationFeedback key={key} role="alert" className="text-destructive">
                                {t(value)}
                            </OperationFeedback>
                        ))}
                        <Button disabled={!target || !form.data.confirmed || form.processing}>
                            {t('Confirm change')}
                        </Button>
                    </fieldset>
                </form>
                <PlatformAccountTable
                    url={url}
                    filters={{}}
                    searchLabel=""
                    showFilters={false}
                    page={history}
                    columns={[
                        { label: 'Previous referrer', render: (r) => r.old_account ?? '—' },
                        { label: 'New referrer', render: (r) => r.new_account },
                        {
                            label: 'Descendants moving with this user',
                            render: (r) => r.descendants,
                        },
                        { label: 'Adjustment reason', render: (r) => r.reason },
                        { label: 'Operator', render: (r) => r.actor_name },
                        { label: 'Time', render: (r) => dateTime(r.created_at) },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
