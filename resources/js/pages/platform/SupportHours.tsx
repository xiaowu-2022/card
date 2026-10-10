import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useEditorRouter } from '@/components/admin/useEditorRouter';
import { useEditor } from '@/components/admin/editor-context';
import { useEditorState } from '@/components/admin/useEditorRouter';
import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { useSupportRequest } from '@/components/support/supportRequest';
import { Button } from '@/components/ui/button';
import { t, useAdminTranslation } from '@/i18n/admin';
type Config = { timezone: string; revision: number; weekly: { start: string; end: string }[][] };
export default function SupportHours({
    companies,
    filters,
    configuration,
}: {
    companies: { id: string; name: string }[];
    filters: { company?: string };
    configuration: Config | null;
}) {
    const router = useEditorRouter();
    const editor = useEditor();
    const supportRequest = useSupportRequest();
    useAdminTranslation();
    const [draft, setDraft] = useState(configuration),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const saved = useRef(false);
    const dirty = JSON.stringify(draft) !== JSON.stringify(configuration);
    useEffect(() => {
        setDraft(configuration);
    }, [configuration]);
    useEditorState(dirty, busy);
    useEffect(() => {
        if (editor) return;
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
    }, [dirty, busy, editor]);
    const change = (day: number, slots: { start: string; end: string }[]) => {
        if (!draft) return;
        saved.current = false;
        setDraft({ ...draft, weekly: draft.weekly.map((old, i) => (i === day ? slots : old)) });
    };
    return (
        <PlatformLayout title={t('Customer support')}>
            <Head title={t('Service hours')} />
            <div className="space-y-4">
                <PlatformSupportTabs hours />
                {!editor && (
                    <label>
                        {t('Company')}
                        <select
                            aria-label={t('Company')}
                            className="ml-3 h-9 rounded-lg border px-3"
                            disabled={busy}
                            value={filters.company ?? ''}
                            onChange={(e) =>
                                router.get(
                                    '/platform/support/hours',
                                    e.target.value ? { company: e.target.value } : {},
                                )
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
                {draft && (
                    <form
                        className="space-y-4 rounded-xl border bg-surface p-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void (async () => {
                                setBusy(true);
                                setError('');
                                try {
                                    await supportRequest('/platform/support/hours', {
                                        company: filters.company,
                                        ...draft,
                                    });
                                    saved.current = true;
                                    router.reload({ only: ['configuration'] });
                                } catch {
                                    setError(
                                        t(
                                            'Unable to save. Refresh and check for overlapping service periods.',
                                        ),
                                    );
                                } finally {
                                    setBusy(false);
                                }
                            })();
                        }}
                    >
                        <p>
                            {t('Company timezone')}: {draft.timezone}
                        </p>
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'No periods means closed. An end earlier than the start continues into the next day. Use 00:00 to 24:00 for all day service.',
                            )}
                        </p>
                        {[
                            'Monday',
                            'Tuesday',
                            'Wednesday',
                            'Thursday',
                            'Friday',
                            'Saturday',
                            'Sunday',
                        ].map((day, index) => (
                            <div key={day} className="flex items-start gap-4 border-t pt-3">
                                <span className="w-24 pt-2">{t(day)}</span>
                                <div className="flex-1 space-y-2">
                                    {draft.weekly[index]!.map((slot, i) => (
                                        <div key={i} className="flex items-center gap-3">
                                            <input
                                                aria-label={t(day) + ' ' + t('Start time')}
                                                className="h-9 rounded-lg border px-3"
                                                type="time"
                                                required
                                                disabled={busy}
                                                value={slot.start}
                                                onChange={(e) =>
                                                    change(
                                                        index,
                                                        draft.weekly[index]!.map((v, n) =>
                                                            n === i
                                                                ? { ...v, start: e.target.value }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            />
                                            <span>–</span>
                                            <input
                                                aria-label={t(day) + ' ' + t('End time')}
                                                className="h-9 w-28 rounded-lg border px-3"
                                                required
                                                pattern="([01][0-9]|2[0-3]):[0-5][0-9]|24:00"
                                                placeholder="18:00"
                                                disabled={busy}
                                                value={slot.end}
                                                onChange={(e) =>
                                                    change(
                                                        index,
                                                        draft.weekly[index]!.map((v, n) =>
                                                            n === i
                                                                ? { ...v, end: e.target.value }
                                                                : v,
                                                        ),
                                                    )
                                                }
                                            />
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                disabled={busy}
                                                onClick={() =>
                                                    change(
                                                        index,
                                                        draft.weekly[index]!.filter(
                                                            (_, n) => n !== i,
                                                        ),
                                                    )
                                                }
                                            >
                                                {t('Remove')}
                                            </Button>
                                        </div>
                                    ))}
                                    {!draft.weekly[index]!.length && (
                                        <p className="py-2 text-muted-foreground">
                                            {t('Service closed')}
                                        </p>
                                    )}
                                </div>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={busy || draft.weekly[index]!.length >= 6}
                                    onClick={() =>
                                        change(index, [
                                            ...draft.weekly[index]!,
                                            { start: '09:00', end: '18:00' },
                                        ])
                                    }
                                >
                                    {t('Add period')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() =>
                                        change(index, [{ start: '00:00', end: '24:00' }])
                                    }
                                >
                                    {t('All day')}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    disabled={busy}
                                    onClick={() => change(index, [])}
                                >
                                    {t('Service closed')}
                                </Button>
                            </div>
                        ))}
                        {error && <OperationFeedback role="alert">{error}</OperationFeedback>}
                        <Button disabled={busy || !dirty}>{t('Save')}</Button>
                    </form>
                )}
            </div>
        </PlatformLayout>
    );
}
