import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { X } from 'lucide-react';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { t, dateTime, errorMessage, useAdminTranslation } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import type { SharedProps } from '@/types/global';

type Candidate = { id: string; account_id: string; email: string };
type Company = { id: string; name: string };
type Batch = {
    id: string;
    company_name: string;
    title: string;
    body: string;
    sender: string;
    recipient_count: number;
    delivered: number;
    read: number;
    created_at: string;
};
type Props = {
    companies: Company[];
    company: string | null;
    batches: { data: Batch[]; next_page_url: string | null; prev_page_url: string | null } | null;
};

function NotificationForm({
    company,
    onSaved,
    onState,
}: {
    company: Company;
    onSaved: () => void;
    onState: (dirty: boolean, busy: boolean) => void;
}) {
    const form = useForm({
        title: '',
        body: '',
        audience: 'selected',
        users: [] as string[],
        request_id: crypto.randomUUID(),
        token: '',
        confirmed: false,
    });
    const [search, setSearch] = useState('');
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [selected, setSelected] = useState<Candidate[]>([]);
    const [preview, setPreview] = useState<{ count: number; token: string } | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    useEffect(() => {
        onState(form.isDirty, form.processing || busy);
    }, [form.isDirty, form.processing, busy, onState]);
    const resetPreview = () => {
        setPreview(null);
        form.setData('token', '');
        form.setData('confirmed', false);
        form.setData('request_id', crypto.randomUUID());
    };
    const searchUsers = async () => {
        setBusy(true);
        setError('');
        try {
            const response = await fetch(
                `/platform/tenants/${company.id}/notifications/candidates?search=${encodeURIComponent(search)}`,
                { headers: { Accept: 'application/json' } },
            );
            if (!response.ok || response.redirected) throw new Error();
            const result = (await response.json()) as { users: Candidate[] };
            setCandidates(result.users);
        } catch {
            setError(t('Could not load recipients. Please try again.'));
        } finally {
            setBusy(false);
        }
    };
    const makePreview = async () => {
        setBusy(true);
        setError('');
        form.clearErrors();
        try {
            const csrf = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '',
            );
            const response = await fetch(`/platform/tenants/${company.id}/notifications/preview`, {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-XSRF-TOKEN': csrf,
                },
                body: JSON.stringify(form.data),
            });
            if (!response.ok || response.redirected) throw new Error();
            const result = (await response.json()) as { count: number; token: string };
            setPreview(result);
            form.setData('token', result.token);
        } catch {
            setError(t('Could not preview. Check the content and recipients, then try again.'));
        } finally {
            setBusy(false);
        }
    };
    return (
        <section className="flex min-h-0 flex-1 flex-col overflow-hidden">
            <div className="min-h-0 space-y-4 overflow-y-auto overscroll-contain py-4">
                <h2 className="text-lg font-semibold">{t('New notification')}</h2>
                <label className="block text-sm">
                    {t('Recipients')}
                    <select
                        className="mt-2 block min-h-10 w-full rounded-md border bg-background p-2"
                        aria-label={t('Recipients')}
                        value={form.data.audience}
                        disabled={busy || form.processing}
                        onChange={(e) => {
                            resetPreview();
                            form.setData('audience', e.target.value);
                        }}
                    >
                        <option value="selected">{t('Selected users')}</option>
                        <option value="all">{t('All current users in this company')}</option>
                    </select>
                </label>
                {form.data.audience === 'selected' && (
                    <div className="space-y-3">
                        <form
                            className="flex gap-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void searchUsers();
                            }}
                        >
                            <Input
                                aria-label={t('Search account ID or email')}
                                value={search}
                                maxLength={100}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                            <Button
                                disabled={busy || !search.trim()}
                                type="submit"
                                variant="secondary"
                            >
                                {t('Search')}
                            </Button>
                        </form>
                        <ul className="max-h-52 space-y-2 overflow-y-auto">
                            {candidates.map((user) => (
                                <li key={user.id}>
                                    <label className="flex items-center gap-3 break-all text-sm">
                                        <Checkbox
                                            checked={form.data.users.includes(user.id)}
                                            disabled={busy || form.processing}
                                            onCheckedChange={(checked) => {
                                                resetPreview();
                                                const next = checked
                                                    ? [
                                                          ...selected.filter(
                                                              (u) => u.id !== user.id,
                                                          ),
                                                          user,
                                                      ]
                                                    : selected.filter((u) => u.id !== user.id);
                                                setSelected(next);
                                                form.setData(
                                                    'users',
                                                    next.map((u) => u.id),
                                                );
                                            }}
                                        />
                                        {user.account_id} · {user.email}
                                    </label>
                                </li>
                            ))}
                        </ul>
                        <p className="text-sm text-muted-foreground">
                            {t('Selected recipients')}: {selected.length}
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {selected.map((user) => (
                                <button
                                    type="button"
                                    key={user.id}
                                    className="inline-flex items-center gap-1 rounded-full border px-3 py-1 text-sm"
                                    onClick={() => {
                                        resetPreview();
                                        const next = selected.filter((u) => u.id !== user.id);
                                        setSelected(next);
                                        form.setData(
                                            'users',
                                            next.map((u) => u.id),
                                        );
                                    }}
                                    aria-label={`${t('Remove recipient')}: ${user.account_id}`}
                                >
                                    {user.account_id}
                                    <X className="size-3" aria-hidden="true" />
                                </button>
                            ))}
                        </div>
                    </div>
                )}
                <label className="block text-sm">
                    {t('Notification title')}
                    <Input
                        className="mt-2"
                        maxLength={100}
                        value={form.data.title}
                        onChange={(e) => {
                            resetPreview();
                            form.setData('title', e.target.value);
                        }}
                    />
                </label>
                <label className="block text-sm">
                    {t('Notification content')}
                    <textarea
                        className="mt-2 block min-h-40 w-full rounded-md border bg-background p-3"
                        maxLength={5000}
                        value={form.data.body}
                        onChange={(e) => {
                            resetPreview();
                            form.setData('body', e.target.value);
                        }}
                    />
                </label>
                {error && (
                    <OperationFeedback role="alert" className="text-sm text-destructive">
                        {error}
                    </OperationFeedback>
                )}
                {Object.values(form.errors).map((message, i) => (
                    <OperationFeedback role="alert" key={i} className="text-sm text-destructive">
                        {errorMessage(message)}
                    </OperationFeedback>
                ))}
                {preview && (
                    <section
                        className="space-y-4 rounded-lg border p-4"
                        aria-label={t('Confirm notification')}
                    >
                        <h3>{t('Confirm notification')}</h3>
                        <p>
                            {company.name} · {t('Recipients')}: {preview?.count}
                        </p>
                        <h3 className="break-words font-semibold">{form.data.title}</h3>
                        <p className="whitespace-pre-wrap break-words leading-7">
                            {form.data.body}
                        </p>
                        <label className="flex gap-3 text-sm">
                            <Checkbox
                                checked={form.data.confirmed}
                                onCheckedChange={(value) =>
                                    form.setData('confirmed', value === true)
                                }
                                disabled={form.processing}
                            />
                            {t('I confirm the content and recipients.')}
                        </label>
                        {Object.values(form.errors).map((message, i) => (
                            <OperationFeedback
                                role="alert"
                                key={i}
                                className="text-sm text-destructive"
                            >
                                {errorMessage(message)}
                            </OperationFeedback>
                        ))}
                    </section>
                )}
            </div>
            <div className="flex shrink-0 justify-end gap-3 border-t pt-4">
                <Button
                    disabled={
                        busy ||
                        form.processing ||
                        !form.data.title.trim() ||
                        !form.data.body.trim() ||
                        (form.data.audience === 'selected' && !selected.length)
                    }
                    onClick={() => void makePreview()}
                >
                    {t('Preview notification')}
                </Button>
                {preview && (
                    <Button
                        disabled={form.processing || !form.data.confirmed}
                        onClick={() =>
                            form.post(`/platform/tenants/${company.id}/notifications`, {
                                preserveScroll: true,
                                onSuccess: () => {
                                    onSaved();
                                    setPreview(null);
                                    form.reset();
                                    setSelected([]);
                                    form.setData('request_id', crypto.randomUUID());
                                },
                            })
                        }
                    >
                        {form.processing ? t('Sending…') : t('Send notification')}
                    </Button>
                )}
            </div>
        </section>
    );
}
export default function Notifications({ companies, company, batches }: Props) {
    useAdminTranslation();
    const canSend =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('notifications.send');
    const [open, setOpen] = useState(false);
    const [targetCompany, setTargetCompany] = useState(company ?? '');
    const [state, setState] = useState({ dirty: false, busy: false });
    const selected = companies.find((c) => c.id === targetCompany);
    return (
        <PlatformLayout
            title={t('Notifications')}
            actions={
                canSend && (
                    <Button
                        onClick={() => {
                            setTargetCompany(company ?? '');
                            setOpen(true);
                        }}
                    >
                        {t('New notification')}
                    </Button>
                )
            }
        >
            <Head title={t('Notifications')} />

            <label className="block max-w-md text-sm">
                {t('Company')}
                <select
                    className="mt-2 min-h-10 w-full rounded-md border bg-background p-2"
                    value={company ?? ''}
                    onChange={(e) =>
                        router.get(
                            '/platform/notifications',
                            e.target.value ? { company: e.target.value } : {},
                        )
                    }
                >
                    <option value="">{t('All companies')}</option>
                    {companies.map((c) => (
                        <option key={c.id} value={c.id}>
                            {c.name}
                        </option>
                    ))}
                </select>
            </label>
            <Dialog
                open={open}
                onOpenChange={(next) => {
                    if (
                        !state.busy &&
                        (next || !state.dirty || confirm(t('Discard unsaved changes?')))
                    )
                        setOpen(next);
                }}
            >
                <DialogContent
                    presentation="drawer"
                    className="flex max-w-3xl flex-col overflow-hidden"
                    closeDisabled={state.busy}
                    closeLabel={t('Close')}
                >
                    <DialogHeader className="shrink-0">
                        <DialogTitle>{t('New notification')}</DialogTitle>
                        <DialogDescription>{t('Choose a company')}</DialogDescription>
                    </DialogHeader>
                    <select
                        className="w-full rounded border p-2"
                        disabled={state.busy}
                        aria-label={t('Company')}
                        value={targetCompany}
                        onChange={(e) => {
                            if (!state.dirty || confirm(t('Discard unsaved changes?'))) {
                                setTargetCompany(e.target.value);
                                setState({ dirty: false, busy: false });
                            }
                        }}
                    >
                        <option value="">{t('Choose a company')}</option>
                        {companies.map((c) => (
                            <option key={c.id} value={c.id}>
                                {c.name}
                            </option>
                        ))}
                    </select>
                    {selected && canSend && (
                        <NotificationForm
                            key={selected.id}
                            company={selected}
                            onSaved={() => setOpen(false)}
                            onState={(dirty, busy) =>
                                setState((old) =>
                                    old.dirty === dirty && old.busy === busy
                                        ? old
                                        : { dirty, busy },
                                )
                            }
                        />
                    )}
                </DialogContent>
            </Dialog>
            {batches && (
                <section className="space-y-4">
                    <h2 className="text-lg font-semibold">{t('Sent notifications')}</h2>
                    {batches.data.length === 0 && (
                        <p className="text-muted-foreground">{t('No notifications sent yet')}</p>
                    )}
                    {batches.data.map((batch) => (
                        <article key={batch.id} className="rounded-xl border p-4">
                            <h3 className="break-words font-semibold">{batch.title}</h3>
                            <p className="my-3 whitespace-pre-wrap break-words text-sm leading-6">
                                {batch.body}
                            </p>
                            <dl className="flex flex-wrap gap-x-8 gap-y-3 text-sm text-muted-foreground">
                                {[
                                    [t('Company'), batch.company_name],
                                    [t('Operator'), batch.sender],
                                    [t('Sent at'), dateTime(batch.created_at)],
                                    [t('Recipients'), batch.recipient_count],
                                    [t('Delivered'), batch.delivered],
                                    [t('Read'), batch.read],
                                    [
                                        t('Status'),
                                        Number(batch.delivered) === Number(batch.recipient_count)
                                            ? t('Delivery completed')
                                            : t('Delivery pending'),
                                    ],
                                ].map(([label, value]) => (
                                    <div key={label}>
                                        <dt>{label}</dt>
                                        <dd>{value}</dd>
                                    </div>
                                ))}
                            </dl>
                        </article>
                    ))}
                    <nav className="flex justify-between">
                        {batches.prev_page_url ? (
                            <Link preserveScroll href={batches.prev_page_url}>
                                {t('Previous')}
                            </Link>
                        ) : (
                            <span />
                        )}
                        {batches.next_page_url && (
                            <Link preserveScroll href={batches.next_page_url}>
                                {t('Next')}
                            </Link>
                        )}
                    </nav>
                </section>
            )}
        </PlatformLayout>
    );
}
