import { useEditorRouter } from '@/components/admin/useEditorRouter';
import { useEditor } from '@/components/admin/editor-context';
import { useEditorState } from '@/components/admin/useEditorRouter';
import { useEffect, useRef, useState } from 'react';
import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { useSupportRequest, SupportRequestError } from '@/components/support/supportRequest';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Dialog, DialogContent, DialogTitle } from '@/components/admin/InlineEditorDialog';
import { t, useAdminTranslation } from '@/i18n/admin';

type FAQ = {
    id: string | null;
    overrides_id: string | null;
    question: string;
    variants: string[];
    keywords: string[];
    answer: string;
    enabled: boolean;
    archived: boolean;
    revision: number;
};
type Row = {
    id: string;
    question: string;
    scope: string;
    overridden: boolean;
    enabled: boolean;
    archived: boolean;
};
type Match = {
    answer: { answer: string } | null;
    candidates: { id: string; question: string; score: number }[];
};
const empty: FAQ = {
    id: null,
    overrides_id: null,
    question: '',
    variants: [],
    keywords: [],
    answer: '',
    enabled: true,
    archived: false,
    revision: 0,
};
export default function SupportBot({
    faqs,
    filters,
    companies,
    settings,
}: {
    faqs: AccountPage<Row>;
    filters: { company?: string; search?: string };
    companies: { id: string; name: string }[];
    settings: { enabled: boolean; revision: number } | null;
}) {
    const router = useEditorRouter();
    const editor = useEditor();
    const supportRequest = useSupportRequest();
    useAdminTranslation();
    const saved = useRef(false);
    const company = filters.company ?? '';
    const [draft, setDraft] = useState<FAQ | null>(null),
        [original, setOriginal] = useState(''),
        [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const [question, setQuestion] = useState(''),
        [match, setMatch] = useState<Match | null>(null),
        [testing, setTesting] = useState(false);
    const [configBusy, setConfigBusy] = useState(false);
    const dirty = draft !== null && JSON.stringify(draft) !== original;
    useEffect(() => {
        setMatch(null);
    }, [company, settings?.revision]);
    useEditorState(dirty, busy || configBusy);
    useEffect(() => {
        if (editor) return;
        const unload = (event: BeforeUnloadEvent) => {
            if (dirty || busy) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', unload);
        const remove = router.on('before', (event) => {
            if (!saved.current && (busy || (dirty && !confirm(t('Discard unsaved changes?')))))
                event.preventDefault();
        });
        return () => {
            window.removeEventListener('beforeunload', unload);
            remove();
        };
    }, [dirty, busy, editor]);
    const open = async (id?: string) => {
        if (busy || configBusy || (dirty && !confirm(t('Discard unsaved changes?')))) return;
        saved.current = false;
        setBusy(true);
        setError('');
        try {
            const value = id
                ? await supportRequest<FAQ>(
                      `/platform/support/bot/faqs/${id}${company ? '?company=' + company : ''}`,
                  )
                : { ...empty };
            setOriginal(JSON.stringify(value));
            setDraft(value);
        } catch {
            setError(t('Unable to load. Please try again.'));
        } finally {
            setBusy(false);
        }
    };
    const close = () => {
        if (!busy && (!dirty || confirm(t('Discard unsaved changes?')))) setDraft(null);
    };
    return (
        <PlatformLayout
            title={t('Customer support')}
            actions={
                <Button disabled={busy} onClick={() => void open()}>
                    {t('Add FAQ')}
                </Button>
            }
        >
            <Head title={t('Bot and FAQ')} />
            <div className="space-y-4">
                <PlatformSupportTabs bot />
                <div className="flex items-center gap-4 rounded-xl border bg-surface p-4">
                    {!editor && (
                        <label>
                            {t('FAQ scope')}{' '}
                            <select
                                disabled={configBusy || busy}
                                aria-label={t('FAQ scope')}
                                className="ml-3 h-9 rounded-md border px-3"
                                value={company}
                                onChange={(e) =>
                                    router.get(
                                        '/platform/support/bot',
                                        e.target.value ? { company: e.target.value } : {},
                                    )
                                }
                            >
                                <option value="">{t('Public FAQ')}</option>
                                {companies.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </label>
                    )}
                    {settings && (
                        <Button
                            variant="secondary"
                            disabled={configBusy}
                            onClick={() => {
                                void (async () => {
                                    setConfigBusy(true);
                                    setError('');
                                    try {
                                        await supportRequest('/platform/support/bot/settings', {
                                            company,
                                            enabled: !settings.enabled,
                                            revision: settings.revision,
                                        });
                                        router.reload({ only: ['settings'] });
                                    } catch {
                                        setError(
                                            t(
                                                'Settings changed or could not be saved. Refresh and try again.',
                                            ),
                                        );
                                    } finally {
                                        setConfigBusy(false);
                                    }
                                })();
                            }}
                        >
                            {t(settings.enabled ? 'Disable bot' : 'Enable bot')}
                        </Button>
                    )}
                    {settings && (
                        <span>{t(settings.enabled ? 'Bot enabled' : 'Bot disabled')}</span>
                    )}
                </div>
                <p className="text-sm text-muted-foreground">
                    {t(
                        company
                            ? 'Public FAQ applies automatically. Customize its answer or disable it for this company.'
                            : 'Public FAQ applies to companies with the bot enabled. Answers are sent exactly as saved.',
                    )}
                </p>
                {error && (
                    <p role="alert" className="text-destructive">
                        {error}
                    </p>
                )}
                <form
                    className="flex gap-3"
                    onSubmit={(event) => {
                        event.preventDefault();
                        const data = new FormData(event.currentTarget);
                        const search = data.get('search');
                        router.get(
                            '/platform/support/bot',
                            {
                                company: company || undefined,
                                search: typeof search === 'string' ? search : '',
                            },
                            { preserveState: true },
                        );
                    }}
                >
                    <Input
                        key={JSON.stringify(filters)}
                        name="search"
                        defaultValue={filters.search ?? ''}
                        aria-label={t('Search questions')}
                        placeholder={t('Search questions')}
                        maxLength={120}
                    />
                    <Button>{t('Search')}</Button>
                </form>
                <PlatformAccountTable
                    showFilters={false}
                    key={JSON.stringify(filters)}
                    page={faqs}
                    filters={filters}
                    extraQuery={company ? { company } : {}}
                    url="/platform/support/bot"
                    searchLabel={t('Search questions')}
                    columns={[
                        { label: 'Question', render: (row) => row.question },
                        {
                            label: 'Scope',
                            render: (row) =>
                                t(
                                    row.scope === 'company'
                                        ? 'Company FAQ'
                                        : row.overridden
                                          ? 'Company override'
                                          : 'Public FAQ',
                                ),
                        },
                        {
                            label: 'Status',
                            render: (row) =>
                                t(row.archived ? 'Archived' : row.enabled ? 'Enabled' : 'Disabled'),
                        },
                        {
                            label: 'Actions',
                            render: (row) => (
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() => void open(row.id)}
                                >
                                    {t(company && row.scope === 'public' ? 'Customize' : 'Edit')}
                                </Button>
                            ),
                        },
                    ]}
                />
                {company && (
                    <form
                        className="space-y-3 rounded-xl border bg-surface p-4"
                        onSubmit={(event) => {
                            void (async () => {
                                event.preventDefault();
                                setTesting(true);
                                setError('');
                                setMatch(null);
                                try {
                                    setMatch(
                                        await supportRequest<Match>(
                                            '/platform/support/bot/preview',
                                            {
                                                company,
                                                question,
                                            },
                                        ),
                                    );
                                } catch {
                                    setError(t('Unable to load. Please try again.'));
                                } finally {
                                    setTesting(false);
                                }
                            })();
                        }}
                    >
                        <h2 className="font-semibold">{t('Test FAQ matching')}</h2>
                        <div className="flex gap-3">
                            <Input
                                required
                                maxLength={2000}
                                aria-label={t('Customer question')}
                                placeholder={t('Customer question')}
                                value={question}
                                onChange={(e) => setQuestion(e.target.value)}
                            />
                            <Button className="shrink-0 whitespace-nowrap" disabled={testing}>
                                {t('Test match')}
                            </Button>
                        </div>
                        {match && (
                            <div role="status" className="space-y-2">
                                <p className="whitespace-pre-wrap">
                                    {match.answer?.answer ??
                                        t(
                                            'No reliable match. The customer will be offered human support.',
                                        )}
                                </p>
                                {match.candidates.map((c) => (
                                    <p key={c.id} className="text-sm text-muted-foreground">
                                        {c.question} · {Math.round(c.score * 100)}%
                                    </p>
                                ))}
                            </div>
                        )}
                    </form>
                )}
                <Dialog
                    open={!!draft}
                    onOpenChange={(value) => {
                        if (!value) close();
                    }}
                >
                    <DialogContent
                        className="max-h-[90vh] overflow-y-auto"
                        aria-describedby={undefined}
                        closeDisabled={busy}
                    >
                        <DialogTitle>
                            {t(draft?.overrides_id ? 'Company override' : 'Edit FAQ')}
                        </DialogTitle>
                        {draft && (
                            <form
                                className="space-y-4"
                                onSubmit={(event) => {
                                    void (async () => {
                                        event.preventDefault();
                                        setBusy(true);
                                        setError('');
                                        try {
                                            await supportRequest('/platform/support/bot/faqs', {
                                                ...draft,
                                                variants: draft.variants
                                                    .map((v) => v.trim())
                                                    .filter(Boolean),
                                                keywords: draft.keywords
                                                    .map((v) => v.trim())
                                                    .filter(Boolean),
                                                company: company || null,
                                            });
                                            saved.current = true;
                                            setDraft(null);
                                            setOriginal('');
                                            setBusy(false);
                                            router.reload({ only: ['faqs'] });
                                        } catch (e) {
                                            setError(
                                                t(
                                                    e instanceof SupportRequestError &&
                                                        e.status === 409
                                                        ? 'FAQ changed. Close and reopen before saving.'
                                                        : 'Unable to save. Check the fields and try again.',
                                                ),
                                            );
                                        } finally {
                                            setBusy(false);
                                        }
                                    })();
                                }}
                            >
                                <label className="block space-y-2">
                                    <span>{t('Question')}</span>
                                    <Input
                                        required
                                        maxLength={200}
                                        disabled={!!draft.overrides_id || busy}
                                        value={draft.question}
                                        onChange={(e) =>
                                            setDraft({ ...draft, question: e.target.value })
                                        }
                                    />
                                </label>
                                <label className="block space-y-2">
                                    <span id="faq-variants-label">
                                        {t('Similar questions (one per line, up to 20)')}
                                    </span>
                                    <Textarea
                                        aria-label={t('Similar questions (one per line, up to 20)')}
                                        disabled={!!draft.overrides_id || busy}
                                        value={draft.variants.join('\n')}
                                        onChange={(e) =>
                                            setDraft({
                                                ...draft,
                                                variants: e.target.value.split('\n'),
                                            })
                                        }
                                    />
                                </label>
                                <label className="block space-y-2">
                                    <span>
                                        {t('Keywords (one per line, at least 2 characters)')}
                                    </span>
                                    <Textarea
                                        aria-label={t(
                                            'Keywords (one per line, at least 2 characters)',
                                        )}
                                        disabled={!!draft.overrides_id || busy}
                                        value={draft.keywords.join('\n')}
                                        onChange={(e) =>
                                            setDraft({
                                                ...draft,
                                                keywords: e.target.value.split('\n'),
                                            })
                                        }
                                    />
                                </label>
                                <label className="block space-y-2">
                                    <span id="faq-answer-label">{t('Answer (Chinese)')}</span>
                                    <Textarea
                                        aria-label={t('Answer (Chinese)')}
                                        required
                                        maxLength={2000}
                                        disabled={busy}
                                        className="min-h-32"
                                        value={draft.answer}
                                        onChange={(e) =>
                                            setDraft({ ...draft, answer: e.target.value })
                                        }
                                    />
                                </label>
                                <label className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={busy}
                                        checked={draft.enabled}
                                        onChange={(e) =>
                                            setDraft({ ...draft, enabled: e.target.checked })
                                        }
                                    />
                                    {t('Enabled')}
                                </label>
                                <label className="flex gap-2">
                                    <input
                                        type="checkbox"
                                        disabled={busy}
                                        checked={draft.archived}
                                        onChange={(e) =>
                                            setDraft({ ...draft, archived: e.target.checked })
                                        }
                                    />
                                    {t(
                                        draft.overrides_id
                                            ? 'Use public answer again (archive override)'
                                            : 'Archived',
                                    )}
                                </label>
                                {error && (
                                    <p role="alert" className="text-destructive">
                                        {error}
                                    </p>
                                )}
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
        </PlatformLayout>
    );
}
