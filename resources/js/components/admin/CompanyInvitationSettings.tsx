import { useEditor, useForm, usePage } from '@/components/admin/editor-context';
import { router } from '@inertiajs/react';
import { AdminPagination } from '@/components/admin/AdminPagination';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { Button } from '@/components/ui/button';
import { t, dateTime } from '@/i18n/admin';

export type CompanyInvitation = { id: string; code: string; enabled: boolean; revision: number };
export type CompanyInvitationHistory = {
    current_page: number;
    last_page: number;
    total: number;
    data: {
        id: string;
        old_code: string;
        new_code: string;
        enabled: boolean;
        reason: string;
        actor_name: string;
        created_at: string;
    }[];
    links: { url: string | null; label: string; active: boolean }[];
};

export function CompanyInvitationSettings({
    tenant,
    invitation,
    nextCode,
    history,
}: {
    tenant: string;
    invitation: CompanyInvitation;
    nextCode: number;
    history: CompanyInvitationHistory;
}) {
    const editor = useEditor();
    const { url } = usePage();
    const form = useForm({
        old_code: invitation.code,
        new_code: invitation.code,
        enabled: invitation.enabled,
        revision: invitation.revision,
        reason: '',
        confirmed: false,
        request_id: crypto.randomUUID(),
    });
    const changed =
        form.data.new_code !== invitation.code || form.data.enabled !== invitation.enabled;
    const validCode =
        /^[0-9]{6}$/.test(form.data.new_code) &&
        (form.data.new_code === invitation.code || Number(form.data.new_code) >= nextCode);
    const update = (values: Partial<typeof form.data>) =>
        form.setData({
            ...form.data,
            ...values,
            confirmed: false,
            request_id: crypto.randomUUID(),
        });
    return (
        <section className="space-y-4 rounded-xl border bg-surface p-4">
            <h2 className="font-semibold">{t('Company invitation code')}</h2>
            <p>
                {t('Current invitation code')}: {invitation.code} ·{' '}
                {t(invitation.enabled ? 'Enabled' : 'Disabled')}
            </p>
            <p className="text-sm text-muted-foreground">
                {t(
                    'Company code changes affect new registration requests only. Personal invitation codes, accepted verifications and existing relationships remain unchanged.',
                )}
            </p>
            <form
                className="max-w-2xl space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    form.post(`/platform/tenants/${tenant}/configuration/company-invitation`, {
                        preserveScroll: true,
                    });
                }}
            >
                <fieldset className="space-y-4" disabled={form.processing}>
                    <label className="block">
                        {t('Company invitation code')}
                        <input
                            className="mt-1 block h-9 w-full rounded border px-3"
                            required
                            inputMode="numeric"
                            pattern="[0-9]{6}"
                            minLength={6}
                            maxLength={6}
                            value={form.data.new_code}
                            onChange={(event) => update({ new_code: event.target.value })}
                        />
                    </label>
                    <p className="text-sm text-muted-foreground">
                        {t('Available invitation code range')}:{' '}
                        {nextCode <= 999999
                            ? `${nextCode}–999999`
                            : t('Invitation codes are exhausted.')}{' '}
                        ·{' '}
                        {t('The code must never have been used. Availability is checked on save.')}
                    </p>
                    <label className="flex items-center gap-2">
                        <input
                            type="checkbox"
                            checked={form.data.enabled}
                            onChange={(event) => update({ enabled: event.target.checked })}
                        />
                        {t('Enable company invitation code')}
                    </label>
                    <label className="block">
                        {t('Adjustment reason')}
                        <textarea
                            className="mt-1 block min-h-20 w-full rounded border p-3"
                            required
                            maxLength={500}
                            value={form.data.reason}
                            onChange={(event) => update({ reason: event.target.value })}
                        />
                    </label>
                    <p>
                        {invitation.code} ({t(invitation.enabled ? 'Enabled' : 'Disabled')}) →{' '}
                        {form.data.new_code || '—'} ({t(form.data.enabled ? 'Enabled' : 'Disabled')}
                        )
                    </p>
                    <label className="flex gap-2">
                        <input
                            type="checkbox"
                            required
                            checked={form.data.confirmed}
                            onChange={(event) => form.setData('confirmed', event.target.checked)}
                        />
                        {t(
                            'I confirm this company invitation change. Replaced or disabled codes cannot accept new registration requests.',
                        )}
                    </label>
                    {Object.entries(form.errors).map(([key, error]) => (
                        <OperationFeedback key={key} role="alert">
                            {t(error)}
                        </OperationFeedback>
                    ))}
                    <Button
                        disabled={
                            !changed ||
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
            <div className="overflow-x-auto">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr>
                            {[
                                'Previous invitation code',
                                'New invitation code',
                                'Status',
                                'Adjustment reason',
                                'Operator',
                                'Time',
                            ].map((label) => (
                                <th key={label} className="p-2">
                                    {t(label)}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody>
                        {history.data.map((row) => (
                            <tr key={row.id} className="border-t">
                                <td className="p-2">{row.old_code}</td>
                                <td className="p-2">{row.new_code}</td>
                                <td className="p-2">{t(row.enabled ? 'Enabled' : 'Disabled')}</td>
                                <td className="p-2">{row.reason}</td>
                                <td className="p-2">{row.actor_name}</td>
                                <td className="whitespace-nowrap p-2">
                                    {dateTime(row.created_at)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            {!history.data.length && (
                <p className="text-sm text-muted-foreground">{t('No matching records.')}</p>
            )}
            <AdminPagination
                page={history.current_page}
                pages={history.last_page}
                total={history.total}
                onPage={(page) => {
                    const query = new URL(url, location.origin).searchParams;
                    query.set('invitation_page', String(page));
                    const target = `/platform/tenants/${tenant}/configuration/paid-promotion?${query}`;
                    if (editor) editor.navigate(target);
                    else router.get(target, {}, { preserveState: true, preserveScroll: true });
                }}
            />
        </section>
    );
}
