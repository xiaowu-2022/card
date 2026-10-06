import { useForm } from '@/components/admin/editor-context';
import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';

type Props = {
    account: {
        id: string;
        companyId: string;
        companyName: string;
        email: string;
        accountId: string;
    };
    currentCode: string;
    revision: number;
    nextCode: number;
    canChange: boolean;
    history: AccountPage<{
        id: string;
        old_code: string;
        new_code: string;
        reason: string;
        actor_name: string;
        created_at: string;
    }>;
};

export default function UserInvitationCode({
    account,
    currentCode,
    revision,
    nextCode,
    canChange,
    history,
}: Props) {
    useAdminTranslation();
    const url = `/platform/tenants/${account.companyId}/users/${account.id}/invitation-code`;
    const form = useForm({
        new_code: '',
        old_code: currentCode,
        revision,
        reason: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
    const validCode =
        /^[0-9]{6}$/.test(form.data.new_code) && Number(form.data.new_code) >= nextCode;
    const available = canChange && nextCode <= 999999;
    function change(field: 'new_code' | 'reason', value: string) {
        form.setData({
            ...form.data,
            [field]: value,
            confirmed: false,
            request_id: crypto.randomUUID(),
        });
    }
    return (
        <PlatformLayout
            title={t('Change invitation code')}
            description={`${account.companyName} · ${account.accountId}`}
        >
            <Head title={t('Change invitation code')} />
            <div className="space-y-4">
                <p>
                    {account.companyName} · {account.accountId} · {account.email}
                </p>
                <p>
                    {t('Current invitation code')}: {currentCode}
                </p>
                <p>
                    {t(
                        'Old invitation codes and invitation links will stop working. Existing team relationships remain unchanged.',
                    )}
                </p>
                {!canChange && (
                    <p role="alert">{t('Choose an active user in an active company.')}</p>
                )}
                {nextCode > 999999 ? (
                    <p role="alert">{t('Invitation codes are exhausted.')}</p>
                ) : (
                    <p>
                        {t('Available invitation code range')}: {nextCode}–999999.{' '}
                        {t('The code must never have been used. Availability is checked on save.')}
                    </p>
                )}
                <form
                    className="max-w-2xl space-y-4 rounded border p-4"
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(url, { preserveState: false });
                    }}
                >
                    <fieldset className="space-y-4" disabled={form.processing || !available}>
                        <label className="block">
                            {t('New invitation code')}
                            <input
                                required
                                inputMode="numeric"
                                pattern="[0-9]{6}"
                                minLength={6}
                                maxLength={6}
                                className="block h-9 w-full rounded border px-3"
                                value={form.data.new_code}
                                onChange={(e) => change('new_code', e.target.value)}
                            />
                        </label>
                        <label className="block">
                            {t('Adjustment reason')}
                            <textarea
                                required
                                maxLength={500}
                                className="block min-h-24 w-full rounded border p-3"
                                value={form.data.reason}
                                onChange={(e) => change('reason', e.target.value)}
                            />
                        </label>
                        <p>
                            {currentCode} → {form.data.new_code || '—'}
                        </p>
                        <label className="flex gap-2">
                            <input
                                type="checkbox"
                                required
                                checked={form.data.confirmed}
                                onChange={(e) => form.setData('confirmed', e.target.checked)}
                            />
                            {t(
                                'I confirm the invitation code change and immediate expiry of old invitation links.',
                            )}
                        </label>
                        {Object.entries(form.errors).map(([key, value]) => (
                            <p key={key} role="alert" className="text-destructive">
                                {t(value)}
                            </p>
                        ))}
                        <Button
                            disabled={
                                !available ||
                                !validCode ||
                                !form.data.reason.trim() ||
                                !form.data.confirmed ||
                                form.processing
                            }
                        >
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
                        { label: 'Previous invitation code', render: (r) => r.old_code },
                        { label: 'New invitation code', render: (r) => r.new_code },
                        { label: 'Adjustment reason', render: (r) => r.reason },
                        { label: 'Operator', render: (r) => r.actor_name },
                        { label: 'Time', render: (r) => dateTime(r.created_at) },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
