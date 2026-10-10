import { showOperationResult } from './operation-result';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useEffect, useRef, useState } from 'react';
import { t, dateTime } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Table,
    TableHeader,
    TableBody,
    TableRow,
    TableHead,
    TableCell,
} from '@/components/ui/table';

export type SyncCard = { id: string; tenantId: string; maskedPan: string };
export type SyncScope = { company?: string; cards?: SyncCard[] };
type Counts = Record<
    'total' | 'pending' | 'succeeded' | 'failed' | 'skipped' | 'pages' | 'records',
    number
>;
type Batch = {
    id: string;
    tenant_id: string | null;
    date_from: string;
    date_to: string;
    created_at: string;
    scope: 'selected' | 'company' | 'all';
    execution_mode: 'browser' | 'queue';
    counts: Counts;
    status: string;
};
type Progress = {
    counts: Counts;
    status: string;
    details: {
        items: {
            id: string;
            masked_pan: string;
            tenant_id: string;
            status: string;
            error_code: string;
            next_page: number;
        }[];
        page: number;
        hasMore: boolean;
    };
};
const base = '/platform/card-transaction-batches';
async function api<T>(path: string, body?: object, signal?: AbortSignal): Promise<T> {
    const xsrf = document.cookie
        .split('; ')
        .find((row) => row.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const token = xsrf
        ? decodeURIComponent(xsrf)
        : document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    if (body && !token) throw new Error('Session expired. Refresh the page and sign in again.');
    const response = await fetch(base + path, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        signal: signal
            ? AbortSignal.any([signal, AbortSignal.timeout(30000)])
            : AbortSignal.timeout(30000),
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { [xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN']: token } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok || response.redirected) {
        if (response.redirected || [401, 419].includes(response.status))
            throw new Error('Session expired. Refresh the page and sign in again.');
        if (response.status === 403)
            throw new Error('You do not have permission to sync transactions.');
        if (response.status === 409)
            throw new Error('The sync request has changed. Close and reopen the sync dialog.');
        if (response.status === 429)
            throw new Error('Too many sync requests. Wait one minute and retry.');
        if (response.status === 422) {
            const data = (await response.json()) as { errors?: Record<string, unknown> };
            throw new Error(
                Object.keys(data.errors ?? {}).some((key) => key.startsWith('card_ids'))
                    ? 'Selected cards are unavailable in this company.'
                    : 'Check the date range. Select at most 366 days through today.',
            );
        }
        throw new Error('Unable to load. Please try again.');
    }
    return (await response.json()) as T;
}
const reasons: Record<string, string> = {
    invalid_provider_binding: 'No valid PhotonPay card binding',
    binding_changed: 'Card provider binding changed',
    permission_revoked: 'Operator permission was revoked',
    provider_unavailable: 'Card provider is unavailable',
    provider_rate_limit: 'Provider rate limit reached',
    provider_read_failed: 'Provider response could not be confirmed',
    invalid_pagination: 'Invalid provider pagination',
    invalid_transaction_time: 'Invalid provider transaction time',
    worker_timeout: 'Worker stopped repeatedly',
    sync_failed: 'Transaction synchronization failed',
};
function dayOffset(day: string, offset: number) {
    const date = new Date(day + 'T00:00:00Z');
    date.setUTCDate(date.getUTCDate() + offset);
    return date.toISOString().slice(0, 10);
}
const statusLabel = (status: string) =>
    t(
        status === 'RETIRED'
            ? 'Legacy sync retired'
            : status === 'RUNNING'
              ? 'Sync in progress'
              : status === 'PARTIAL_FAILED'
                ? 'Sync completed with failures'
                : 'Sync completed',
    );
const errorText = (error: unknown) =>
    error instanceof Error && error.name === 'Error'
        ? error.message
        : 'Unable to load. Please try again.';

export function CardTransactionBatchSync({
    companies,
    scope,
    onClose,
    onCompleted,
}: {
    companies: { id: string; name: string }[];
    scope: SyncScope | null;
    onClose: () => void;
    onCompleted: () => void;
}) {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const [tenant, setTenant] = useState(''),
        [from, setFrom] = useState(today),
        [to, setTo] = useState(today);
    const [preset, setPreset] = useState('today');
    const [intent, setIntent] = useState(() => crypto.randomUUID());
    const [preview, setPreview] = useState<{
        total: number;
        eligible: number;
        skipped: number;
    } | null>(null);
    const [previewAttempt, setPreviewAttempt] = useState(0),
        [previewError, setPreviewError] = useState('');
    const [batches, setBatches] = useState<Batch[]>([]),
        [loading, setLoading] = useState(true);
    const [refresh, setRefresh] = useState(0),
        [listError, setListError] = useState(''),
        [error, setError] = useState('');
    const [busy, setBusy] = useState(false),
        [retrying, setRetrying] = useState('');
    const [detailId, setDetailId] = useState(''),
        [detailPage, setDetailPage] = useState(1),
        [detail, setDetail] = useState<Progress | null>(null),
        [detailError, setDetailError] = useState('');
    const [runningId, setRunningId] = useState(''),
        [runError, setRunError] = useState('');
    const statuses = useRef(new Map<string, string>()),
        submitted = useRef(new Set<string>());
    const completed = useRef(onCompleted);
    completed.current = onCompleted;
    const submitLock = useRef(false);
    const companyName = (id: string | null) =>
        id ? (companies.find((c) => c.id === id)?.name ?? t('Company')) : t('All companies');
    useEffect(() => {
        if (!scope) return;
        setTenant(scope.company ?? '');
        setFrom(today);
        setTo(today);
        setPreset('today');
        setIntent(crypto.randomUUID());
        setError('');
        setPreview(null);
        setPreviewError('');
    }, [scope]);
    useEffect(() => {
        if (!scope) return;
        const controller = new AbortController();
        setPreview(null);
        setPreviewError('');
        // POST avoids oversized query strings for selected cards; preview does not create work.
        void api<{ total: number; eligible: number; skipped: number }>(
            '/preview',
            {
                tenant_id: scope.cards ? scope.company || null : tenant || null,
                ...(scope.cards ? { card_ids: scope.cards.map((c) => c.id) } : {}),
            },
            controller.signal,
        )
            .then((value) => {
                if (!controller.signal.aborted) setPreview(value);
            })
            .catch((e) => {
                if (!controller.signal.aborted) setPreviewError(errorText(e));
            });
        return () => controller.abort();
    }, [scope, tenant, previewAttempt]);
    useEffect(() => {
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        async function poll() {
            try {
                const result = await api<{ items: Batch[] }>('', undefined, controller.signal);
                if (controller.signal.aborted) return;
                let changed = false;
                for (const batch of result.items) {
                    if (
                        batch.status !== 'RUNNING' &&
                        (statuses.current.get(batch.id) === 'RUNNING' ||
                            submitted.current.has(batch.id))
                    ) {
                        changed = true;
                        submitted.current.delete(batch.id);
                    }
                    statuses.current.set(batch.id, batch.status);
                }
                setBatches(result.items);
                setListError('');
                if (changed) completed.current();
                timer = setTimeout(
                    () => void poll(),
                    result.items.some((b) => b.status === 'RUNNING') ? 3000 : 15000,
                );
            } catch (e) {
                if (!controller.signal.aborted) setListError(errorText(e));
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }
        void poll();
        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [refresh]);
    useEffect(() => {
        setDetail(null);
    }, [detailId, detailPage]);
    useEffect(() => {
        setDetailError('');
        if (!detailId) return;
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        async function poll() {
            try {
                const result = await api<Progress>(
                    '/' + detailId + '?page=' + detailPage,
                    undefined,
                    controller.signal,
                );
                if (controller.signal.aborted) return;
                setDetail(result);
                if (result.status === 'RUNNING') timer = setTimeout(() => void poll(), 3000);
            } catch (e) {
                if (!controller.signal.aborted) setDetailError(errorText(e));
            }
        }
        void poll();
        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [detailId, detailPage, refresh]);
    useEffect(() => {
        if (!runningId) return;
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        async function advance() {
            try {
                const result = await api<{ status: string; waitMs: number }>(
                    '/' + runningId + '/advance',
                    {},
                    controller.signal,
                );
                if (controller.signal.aborted) return;
                if (
                    !['RUNNING', 'PARTIAL_FAILED', 'COMPLETED'].includes(result.status) ||
                    !Number.isFinite(result.waitMs)
                ) {
                    throw new Error('Unable to load. Please try again.');
                }
                setRefresh((value) => value + 1);
                if (result.status !== 'RUNNING') {
                    submitted.current.delete(runningId);
                    statuses.current.set(runningId, result.status);
                    completed.current();
                    showOperationResult(
                        result.status === 'COMPLETED' ? 'success' : 'error',
                        result.status === 'COMPLETED'
                            ? 'Sync completed.'
                            : 'Sync finished with failed cards. Review the failed cards before retrying.',
                    );
                    setRunningId('');
                    return;
                }
                timer = setTimeout(
                    () => void advance(),
                    Math.min(30000, Math.max(1100, result.waitMs)),
                );
            } catch (error) {
                if (!controller.signal.aborted) {
                    setRunError(errorText(error));
                    setRunningId('');
                    setRefresh((value) => value + 1);
                }
            }
        }
        void advance();
        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [runningId]);
    function start(id: string) {
        setRunError('');
        submitted.current.add(id);
        setRunningId(id);
    }
    function changeIntent() {
        setIntent(crypto.randomUUID());
        setError('');
    }
    async function submit() {
        if (submitLock.current || !scope || !preview) return;
        submitLock.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await api<{ id: string }>('', {
                tenant_id: scope.cards ? scope.company || null : tenant || null,
                date_from: from,
                date_to: to,
                request_id: intent,
                ...(scope.cards ? { card_ids: scope.cards.map((c) => c.id) } : {}),
            });
            start(result.id);
            setRefresh((v) => v + 1);
            onClose();
        } catch (e) {
            setError(errorText(e));
        } finally {
            submitLock.current = false;
            setBusy(false);
        }
    }
    async function retry(id: string) {
        if (submitLock.current) return;
        submitLock.current = true;
        setRetrying(id);
        setListError('');
        try {
            await api('/' + id + '/retry', {});
            if (batches.find((batch) => batch.id === id)?.execution_mode === 'browser') start(id);
            else submitted.current.add(id);
            setRefresh((v) => v + 1);
        } catch (e) {
            setListError(errorText(e));
        } finally {
            submitLock.current = false;
            setRetrying('');
        }
    }
    const validDates =
        from &&
        to &&
        from <= to &&
        to <= today &&
        (Date.parse(to) - Date.parse(from)) / 86400000 < 366;
    return (
        <>
            <section
                className="mb-4 min-w-0 rounded-xl border bg-surface"
                aria-label={t('Recent sync tasks')}
            >
                <div className="flex items-center justify-between gap-3 p-3">
                    <div>
                        <h2 className="font-semibold">{t('Recent sync tasks')}</h2>
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Keep this page open while syncing. After leaving, select Continue sync to resume. Your latest 20 tasks are saved.',
                            )}
                        </p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => setRefresh((v) => v + 1)}>
                        {t('Refresh')}
                    </Button>
                </div>
                {runError && (
                    <OperationFeedback role="alert" className="px-3 pb-3 text-sm text-destructive">
                        {t(runError)}{' '}
                        {t('Select Continue sync to retry from the saved checkpoint.')}
                    </OperationFeedback>
                )}
                {runningId && (
                    <p role="status" className="px-3 pb-3 text-xs text-muted-foreground">
                        {t(
                            'Pausing stops new requests. A page already being processed may still finish.',
                        )}
                    </p>
                )}
                {listError && (
                    <OperationFeedback role="alert" className="px-3 pb-3 text-sm text-destructive">
                        {t(listError)}
                    </OperationFeedback>
                )}
                {loading ? (
                    <p className="p-3" role="status">
                        {t('Loading')}
                    </p>
                ) : !batches.length ? (
                    <p className="px-3 pb-3 text-sm text-muted-foreground">
                        {t('No sync tasks yet.')}
                    </p>
                ) : (
                    <div className="max-h-64 overflow-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    {['Scope', 'Date range', 'Status', 'Progress', 'Actions'].map(
                                        (label) => (
                                            <TableHead key={label}>{t(label)}</TableHead>
                                        ),
                                    )}
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {batches.map((batch) => (
                                    <TableRow key={batch.id}>
                                        <TableCell>
                                            <div
                                                className="max-w-48 truncate"
                                                title={companyName(batch.tenant_id)}
                                            >
                                                {batch.scope === 'selected'
                                                    ? t('Selected cards ({{count}})', {
                                                          count: batch.counts.total,
                                                      })
                                                    : companyName(batch.tenant_id)}
                                            </div>
                                            <span className="text-xs text-muted-foreground">
                                                {dateTime(batch.created_at)}
                                            </span>
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap text-xs">
                                            {batch.date_from} — {batch.date_to}
                                        </TableCell>
                                        <TableCell className="whitespace-nowrap">
                                            {batch.execution_mode === 'browser' &&
                                            batch.status === 'RUNNING' &&
                                            runningId !== batch.id
                                                ? t('Waiting to continue')
                                                : statusLabel(batch.status)}
                                        </TableCell>
                                        <TableCell className="min-w-48">
                                            {batch.status === 'RETIRED' && (
                                                <p className="text-xs text-muted-foreground">
                                                    {t(
                                                        'Legacy background sync is retired. Create a new browser sync.',
                                                    )}
                                                </p>
                                            )}
                                            <progress
                                                className="h-2 w-full"
                                                value={batch.counts.total - batch.counts.pending}
                                                max={Math.max(1, batch.counts.total)}
                                                aria-label={t('Progress')}
                                            />
                                            <div className="text-xs">
                                                {t(
                                                    'Sync counts: {{done}}/{{total}}, failed {{failed}}, skipped {{skipped}}',
                                                    {
                                                        done: batch.counts.succeeded,
                                                        total: batch.counts.total,
                                                        failed: batch.counts.failed,
                                                        skipped: batch.counts.skipped,
                                                    },
                                                )}
                                            </div>
                                        </TableCell>
                                        <TableCell className="sticky right-0 bg-surface">
                                            <div className="flex gap-2 whitespace-nowrap">
                                                {batch.execution_mode === 'browser' &&
                                                    batch.status === 'RUNNING' && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => {
                                                                if (runningId === batch.id)
                                                                    setRunningId('');
                                                                else start(batch.id);
                                                            }}
                                                        >
                                                            {t(
                                                                runningId === batch.id
                                                                    ? 'Pause'
                                                                    : 'Continue sync',
                                                            )}
                                                        </Button>
                                                    )}
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() => {
                                                        setDetailId(
                                                            detailId === batch.id ? '' : batch.id,
                                                        );
                                                        setDetailPage(1);
                                                    }}
                                                >
                                                    {t('Details')}
                                                </Button>
                                                {batch.execution_mode === 'browser' &&
                                                    batch.counts.failed > 0 && (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            disabled={Boolean(retrying) || busy}
                                                            onClick={() => void retry(batch.id)}
                                                        >
                                                            {t(
                                                                retrying === batch.id
                                                                    ? 'Retrying…'
                                                                    : 'Retry failed cards',
                                                            )}
                                                        </Button>
                                                    )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </div>
                )}
                {detailId && (
                    <div className="space-y-2 border-t p-3 text-sm">
                        <p className="break-all text-xs text-muted-foreground">
                            {t('Task ID')}: {detailId}
                        </p>
                        {detailError ? (
                            <p role="alert">
                                <OperationFeedback>{t(detailError)}</OperationFeedback>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => setRefresh((v) => v + 1)}
                                >
                                    {t('Retry')}
                                </Button>
                            </p>
                        ) : !detail ? (
                            <p role="status">{t('Loading')}</p>
                        ) : (
                            <>
                                <p>
                                    {t('Pages processed')}: {detail.counts.pages} ·{' '}
                                    {t('Record writes')}: {detail.counts.records}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {t(
                                        'Record writes include updates and retries, not only new transactions.',
                                    )}
                                </p>
                                {detail.details.items.map((item) => (
                                    <p key={item.id}>
                                        {companyName(item.tenant_id)} · {item.masked_pan} ·{' '}
                                        {t(
                                            reasons[item.error_code] ??
                                                'Transaction synchronization failed',
                                        )}{' '}
                                        · {t('Page')} {item.next_page}
                                    </p>
                                ))}
                                {detail.details.items.length > 0 && (
                                    <div className="flex gap-2">
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={detailPage <= 1}
                                            onClick={() => setDetailPage((v) => v - 1)}
                                        >
                                            {t('Previous')}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="secondary"
                                            disabled={!detail.details.hasMore}
                                            onClick={() => setDetailPage((v) => v + 1)}
                                        >
                                            {t('Next')}
                                        </Button>
                                    </div>
                                )}
                            </>
                        )}
                    </div>
                )}
            </section>
            <Dialog
                open={scope !== null}
                onOpenChange={(open) => {
                    if (!open && !busy) onClose();
                }}
            >
                <DialogContent closeDisabled={busy}>
                    <DialogHeader>
                        <DialogTitle>
                            {t(
                                scope?.cards?.length === 1
                                    ? 'Sync this card'
                                    : 'Sync card transactions',
                            )}
                        </DialogTitle>
                        <DialogDescription>
                            {t(
                                'Choose dates once. This browser automatically processes all pages through the server. Keep this page open.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <fieldset disabled={busy} className="space-y-4">
                        {scope?.cards ? (
                            <div className="text-sm">
                                <p>
                                    {t('Selected cards ({{count}})', { count: scope.cards.length })}
                                </p>
                                {scope.cards.length === 1 && (
                                    <p>
                                        {companyName(scope.cards[0]!.tenantId)} ·{' '}
                                        {scope.cards[0]!.maskedPan}
                                    </p>
                                )}
                            </div>
                        ) : (
                            <label className="block text-sm">
                                {t('Company')}
                                <select
                                    aria-label={t('Company')}
                                    className="mt-1 h-10 w-full rounded border bg-surface px-2"
                                    value={tenant}
                                    onChange={(e) => {
                                        setTenant(e.target.value);
                                        setPreview(null);
                                        changeIntent();
                                    }}
                                >
                                    <option value="">{t('All companies')}</option>
                                    {companies.map((c) => (
                                        <option key={c.id} value={c.id}>
                                            {c.name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        )}
                        <div className="flex flex-wrap gap-2">
                            {(
                                [
                                    ['today', 'Today'],
                                    ['yesterday', 'Yesterday'],
                                    ['week', 'Last 7 days'],
                                    ['custom', 'Custom dates'],
                                ] as const
                            ).map(([key, label]) => (
                                <Button
                                    key={key}
                                    size="sm"
                                    variant={preset === key ? 'default' : 'secondary'}
                                    onClick={() => {
                                        setPreset(key);
                                        if (key !== 'custom') {
                                            setFrom(
                                                dayOffset(
                                                    today,
                                                    key === 'week'
                                                        ? -6
                                                        : key === 'yesterday'
                                                          ? -1
                                                          : 0,
                                                ),
                                            );
                                            setTo(dayOffset(today, key === 'yesterday' ? -1 : 0));
                                        }
                                        changeIntent();
                                    }}
                                >
                                    {t(label)}
                                </Button>
                            ))}
                        </div>
                        <div className="grid grid-cols-2 gap-3">
                            <label className="text-sm">
                                {t('Start date')}
                                <Input
                                    type="date"
                                    value={from}
                                    max={to}
                                    onChange={(e) => {
                                        setFrom(e.target.value);
                                        setPreset('custom');
                                        changeIntent();
                                    }}
                                />
                            </label>
                            <label className="text-sm">
                                {t('End date')}
                                <Input
                                    type="date"
                                    value={to}
                                    min={from}
                                    max={today}
                                    onChange={(e) => {
                                        setTo(e.target.value);
                                        setPreset('custom');
                                        changeIntent();
                                    }}
                                />
                            </label>
                        </div>
                        <p className="text-sm">
                            {from} — {to} · {t('Beijing time')}
                        </p>
                        <p className="text-sm">
                            {t('Estimated cards')}: {preview?.total ?? '…'} · {t('Eligible cards')}:{' '}
                            {preview?.eligible ?? '…'} · {t('Skipped cards')}:{' '}
                            {preview?.skipped ?? '…'}
                        </p>
                        {previewError && (
                            <p role="alert" className="text-sm text-destructive">
                                <OperationFeedback>{t(previewError)}</OperationFeedback>
                                <Button
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => setPreviewAttempt((v) => v + 1)}
                                >
                                    {t('Retry')}
                                </Button>
                            </p>
                        )}
                        <p className="text-xs text-muted-foreground">
                            {t(
                                'Only transactions within the dates are saved. Full provider history is paginated; large batches may take time. Existing transactions and balances are not deleted or settled again.',
                            )}
                        </p>
                        {!validDates && (
                            <p role="alert" className="text-sm text-destructive">
                                {t('Check the date range. Select at most 366 days through today.')}
                            </p>
                        )}
                        {error && (
                            <OperationFeedback role="alert" className="text-sm text-destructive">
                                {t(error)}
                            </OperationFeedback>
                        )}
                        <Button
                            disabled={busy || !preview || !validDates}
                            onClick={() => void submit()}
                        >
                            {t(busy ? 'Submitting…' : 'Confirm and start sync')}
                        </Button>
                    </fieldset>
                </DialogContent>
            </Dialog>
        </>
    );
}
