import { useEffect, useRef, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import type { AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { supportRequest } from './supportRequest';
import { t } from '@/i18n/admin';
export type QuickReply = {
    id: string;
    title: string;
    body: string;
    revision: number;
    tenant_id?: string;
    company?: string;
};
export function QuickReplyManager({
    replies,
    url,
    companies,
    company = '',
}: {
    replies: AccountPage<QuickReply>;
    url: string;
    companies?: { id: string; name: string }[];
    company?: string;
}) {
    const [draft, setDraft] = useState<(QuickReply & { archived: boolean }) | null>(null),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const original = useRef(''),
        saved = useRef(false);
    const dirty = draft !== null && JSON.stringify(draft) !== original.current;
    useEffect(() => {
        const unload = (e: BeforeUnloadEvent) => {
            if (dirty || busy) {
                e.preventDefault();
                e.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', unload);
        const off = router.on('before', (e) => {
            if (!saved.current && (busy || (dirty && !confirm(t('Discard unsaved changes?')))))
                e.preventDefault();
        });
        return () => {
            off();
            window.removeEventListener('beforeunload', unload);
        };
    }, [dirty, busy]);
    const open = (row?: QuickReply) => {
        saved.current = false;
        setError('');
        const d = {
            ...(row ?? {
                id: crypto.randomUUID(),
                title: '',
                body: '',
                revision: 0,
                tenant_id: company,
            }),
            archived: false,
        };
        original.current = JSON.stringify(d);
        setDraft(d);
    };
    const close = () => {
        if (!busy && (!dirty || confirm(t('Discard unsaved changes?')))) setDraft(null);
    };
    return (
        <div className="space-y-4">
            <Button onClick={() => open()}>{t('Add quick reply')}</Button>
            <div className="overflow-hidden rounded-xl border bg-surface">
                <table className="w-full text-left text-sm">
                    <thead>
                        <tr className="border-b">
                            <th className="p-3">{t('Title')}</th>
                            {companies && <th>{t('Company')}</th>}
                            <th>{t('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {replies.data.map((row) => (
                            <tr key={row.id} className="border-b">
                                <td className="p-3">{row.title}</td>
                                {companies && <td>{row.company}</td>}
                                <td>
                                    <Button variant="secondary" onClick={() => open(row)}>
                                        {t('Edit')}
                                    </Button>
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
                {!replies.data.length && <p className="p-5">{t('No quick replies yet.')}</p>}
                <div className="flex justify-between p-3">
                    {replies.prev_page_url && (
                        <Link href={replies.prev_page_url}>{t('Previous')}</Link>
                    )}
                    <span>
                        {replies.current_page} / {replies.last_page}
                    </span>
                    {replies.next_page_url && <Link href={replies.next_page_url}>{t('Next')}</Link>}
                </div>
            </div>
            <Dialog
                open={!!draft}
                onOpenChange={(o) => {
                    if (!o) close();
                }}
            >
                <DialogContent aria-describedby={undefined} closeDisabled={busy}>
                    <DialogTitle>{t('Quick reply')}</DialogTitle>
                    {draft && (
                        <form
                            className="space-y-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                void (async () => {
                                    setBusy(true);
                                    setError('');
                                    try {
                                        await supportRequest(url, {
                                            ...draft,
                                            company: draft.tenant_id,
                                        });
                                        saved.current = true;
                                        setDraft(null);
                                        router.reload({ only: ['replies'] });
                                    } catch {
                                        setError(
                                            t('Unable to save. Refresh and check the fields.'),
                                        );
                                    } finally {
                                        setBusy(false);
                                    }
                                })();
                            }}
                        >
                            {companies && (
                                <label className="block">
                                    {t('Company')}
                                    <select
                                        aria-label={t('Company')}
                                        required
                                        disabled={busy || draft.revision > 0}
                                        className="mt-2 block h-9 w-full rounded-lg border px-3"
                                        value={draft.tenant_id ?? ''}
                                        onChange={(e) =>
                                            setDraft({ ...draft, tenant_id: e.target.value })
                                        }
                                    >
                                        <option value="">{t('Select company')}</option>
                                        {companies.map((c) => (
                                            <option key={c.id} value={c.id}>
                                                {c.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            )}
                            <label className="block">
                                {t('Title')}
                                <Input
                                    required
                                    maxLength={100}
                                    disabled={busy}
                                    value={draft.title}
                                    onChange={(e) => setDraft({ ...draft, title: e.target.value })}
                                />
                            </label>
                            <label className="block">
                                {t('Reply text')}
                                <Textarea
                                    aria-label={t('Reply text')}
                                    required
                                    maxLength={2000}
                                    disabled={busy}
                                    value={draft.body}
                                    onChange={(e) => setDraft({ ...draft, body: e.target.value })}
                                />
                            </label>
                            {draft.revision > 0 && (
                                <label className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={busy}
                                        checked={draft.archived}
                                        onChange={(e) =>
                                            setDraft({ ...draft, archived: e.target.checked })
                                        }
                                    />
                                    {t('Delete this quick reply')}
                                </label>
                            )}
                            {error && <p role="alert">{error}</p>}
                            <div className="flex justify-end gap-3">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={close}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button disabled={busy}>{t('Save')}</Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}
