import {
    UserReferrerSelect,
    referrerCandidateLabel,
    type ReferrerCandidate,
} from '@/components/admin/UserReferrerSelect';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useForm } from '@/components/admin/editor-context';
import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
type Member = ReferrerCandidate;
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
export default function UserReferrer(props: Props) {
    return <UserReferrerForm key={`${props.account.companyId}:${props.account.id}`} {...props} />;
}
function UserReferrerForm({ account, current, revision, descendants, history }: Props) {
    useAdminTranslation();
    const url = `/platform/tenants/${account.companyId}/users/${account.id}/referrer`;
    const [target, setTarget] = useState<Member | null>(null);
    const form = useForm({
        new_inviter_id: '',
        old_inviter_id: current?.id ?? null,
        revision,
        reason: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
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
                    {t('Current referrer')}: {current ? referrerCandidateLabel(current) : '—'} ·{' '}
                    {t('Descendants moving with this user')}: {descendants}
                </p>
                <p>
                    {t(
                        'Existing commissions and activation snapshots remain unchanged. Future business uses the new relationship.',
                    )}
                </p>
                <form
                    className="max-w-2xl space-y-4 rounded border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url, { preserveState: false });
                    }}
                >
                    <fieldset className="space-y-4" disabled={form.processing}>
                        <div className="space-y-1">
                            <p>{t('New referrer')}</p>
                            <UserReferrerSelect
                                key={url}
                                url={url}
                                value={target}
                                disabled={form.processing}
                                onBrowse={() => form.setData('confirmed', false)}
                                onChange={(candidate) => {
                                    setTarget(candidate);
                                    form.setData({
                                        ...form.data,
                                        new_inviter_id: candidate.id,
                                        confirmed: false,
                                        request_id: crypto.randomUUID(),
                                    });
                                }}
                            />
                        </div>
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
                            {current ? referrerCandidateLabel(current) : '—'} {'→'}{' '}
                            {target ? referrerCandidateLabel(target) : '—'} ·{' '}
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
