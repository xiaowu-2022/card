import { useEffect, useState } from 'react';
import { t } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

type Batch = { id: string; tenant_id: string | null; date_from: string; date_to: string };
type Progress = {
    batch: Batch;
    status: string;
    counts: Record<
        'total' | 'pending' | 'succeeded' | 'failed' | 'skipped' | 'pages' | 'records',
        number
    >;
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
        signal,
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { [xsrf ? 'X-XSRF-TOKEN' : 'X-CSRF-TOKEN']: token } : {}),
        },
        ...(body ? { body: JSON.stringify(body) } : {}),
    });
    if (!response.ok || response.redirected)
        throw new Error(
            response.status === 422
                ? 'Check the date range. Select at most 366 days through today.'
                : 'Unable to load. Please try again.',
        );
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
export function CardTransactionBatchSync({
    companies,
    company,
}: {
    companies: { id: string; name: string }[];
    company?: string;
}) {
    const today = new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
    const [open, setOpen] = useState(false);
    const [tenant, setTenant] = useState(company ?? '');
    const [from, setFrom] = useState(today),
        [to, setTo] = useState(today);
    const [preview, setPreview] = useState<{
        total: number;
        eligible: number;
        skipped: number;
    } | null>(null);
    const [batches, setBatches] = useState<Batch[]>([]);
    const [id, setId] = useState('');
    const [page, setPage] = useState(1);
    const [refresh, setRefresh] = useState(0);
    const [progress, setProgress] = useState<Progress | null>(null);
    const [busy, setBusy] = useState(false),
        [error, setError] = useState('');
    const [intent, setIntent] = useState(() => crypto.randomUUID());
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        setPreview(null);
        void api<{ total: number; eligible: number; skipped: number }>(
            '/preview' + (tenant ? '?tenant_id=' + tenant : ''),
            undefined,
            controller.signal,
        )
            .then(setPreview)
            .catch(() => {
                if (!controller.signal.aborted) setError('Unable to load. Please try again.');
            });
        return () => controller.abort();
    }, [open, tenant]);
    useEffect(() => {
        if (!open) return;
        const controller = new AbortController();
        void api<{ items: Batch[] }>('', undefined, controller.signal)
            .then((data) => setBatches(data.items))
            .catch(() => {
                if (!controller.signal.aborted) setError('Unable to load. Please try again.');
            });
        return () => controller.abort();
    }, [open, id]);
    useEffect(() => {
        setProgress(null);
        if (!open || !id) return;
        const controller = new AbortController();
        let timer: ReturnType<typeof setTimeout>;
        async function poll() {
            try {
                const data = await api<Progress>(
                    '/' + id + '?page=' + page,
                    undefined,
                    controller.signal,
                );
                if (controller.signal.aborted) return;
                setProgress(data);
                if (data.status === 'RUNNING')
                    timer = setTimeout(() => {
                        void poll();
                    }, 3000);
            } catch {
                if (!controller.signal.aborted) setError('Unable to load. Please try again.');
            }
        }
        void poll();
        return () => {
            controller.abort();
            clearTimeout(timer);
        };
    }, [open, id, page, refresh]);
    async function submit(retry = false) {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            const data = await api<{ id: string }>(
                retry ? '/' + id + '/retry' : '',
                retry
                    ? {}
                    : {
                          tenant_id: tenant || null,
                          date_from: from,
                          date_to: to,
                          request_id: intent,
                      },
            );
            setId(data.id);
            setPage(1);
            setProgress(await api<Progress>('/' + data.id));
            setRefresh((value) => value + 1);
            if (!retry) setIntent(crypto.randomUUID());
        } catch (e) {
            setError(e instanceof Error ? e.message : 'Unable to load. Please try again.');
        } finally {
            setBusy(false);
        }
    }
    function changeIntent() {
        setIntent(crypto.randomUUID());
        setError('');
    }
    const companyName = (value: string | null) =>
        value ? (companies.find((c) => c.id === value)?.name ?? value) : t('All companies');
    return (
        <>
            <Button className="mb-3" onClick={() => setOpen(true)}>
                {t('Bulk sync card transactions')}
            </Button>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="max-h-[90vh] max-w-3xl overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{t('Bulk sync card transactions')}</DialogTitle>
                        <DialogDescription>
                            {t(
                                'Sync all cards in the selected scope. Dates use Beijing time. Closing this window does not stop the task.',
                            )}
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid grid-cols-3 gap-3">
                        <label>
                            {t('Company')}
                            <select
                                className="mt-1 h-10 w-full rounded border px-2"
                                value={tenant}
                                onChange={(e) => {
                                    setTenant(e.target.value);
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
                        <label>
                            {t('Start date')}
                            <Input
                                type="date"
                                value={from}
                                max={to}
                                onChange={(e) => {
                                    setFrom(e.target.value);
                                    changeIntent();
                                }}
                            />
                        </label>
                        <label>
                            {t('End date')}
                            <Input
                                type="date"
                                value={to}
                                min={from}
                                max={today}
                                onChange={(e) => {
                                    setTo(e.target.value);
                                    changeIntent();
                                }}
                            />
                        </label>
                    </div>
                    <p className="text-sm">
                        {companyName(tenant || null)} · {from} — {to} · {t('Beijing time')}
                    </p>
                    <p className="text-sm">
                        {t('Estimated cards')}: {preview?.total ?? '…'} · {t('Eligible cards')}:{' '}
                        {preview?.eligible ?? '…'} · {t('Skipped cards')}: {preview?.skipped ?? '…'}
                    </p>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Only transactions within the dates are saved. Full provider history is paginated; large batches may take time. Existing transactions and balances are not deleted or settled again.',
                        )}
                    </p>
                    <Button
                        disabled={busy || !preview || !from || !to || from > to || to > today}
                        onClick={() => void submit()}
                    >
                        {t('Confirm and start sync')}
                    </Button>
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {t(error)}
                        </p>
                    )}
                    <label>
                        {t('Recent sync tasks')}
                        <select
                            className="mt-1 h-10 w-full rounded border px-2"
                            value={id}
                            onChange={(e) => {
                                setId(e.target.value);
                                setPage(1);
                            }}
                        >
                            <option value="">{t('Select a task')}</option>
                            {batches.map((b) => (
                                <option value={b.id} key={b.id}>
                                    {companyName(b.tenant_id)} · {b.date_from} — {b.date_to} ·{' '}
                                    {b.id.slice(0, 8)}
                                </option>
                            ))}
                        </select>
                    </label>
                    {progress && (
                        <section className="space-y-3 border-t pt-3">
                            <p>
                                {companyName(progress.batch.tenant_id)} · {progress.batch.date_from}{' '}
                                — {progress.batch.date_to} ·{' '}
                                {t(
                                    progress.status === 'RUNNING'
                                        ? 'Sync in progress'
                                        : progress.status === 'PARTIAL_FAILED'
                                          ? 'Sync completed with failures'
                                          : 'Sync completed',
                                )}
                            </p>
                            <dl className="grid grid-cols-4 gap-2">
                                {(
                                    [
                                        ['total', 'Total cards'],
                                        ['pending', 'Pending cards'],
                                        ['succeeded', 'Successful cards'],
                                        ['failed', 'Failed cards'],
                                        ['skipped', 'Skipped cards'],
                                        ['pages', 'Pages processed'],
                                        ['records', 'Record writes'],
                                    ] as const
                                ).map(([key, label]) => (
                                    <div key={key}>
                                        <dt className="text-sm text-muted-foreground">
                                            {t(label)}
                                        </dt>
                                        <dd>{progress.counts[key]}</dd>
                                    </div>
                                ))}
                            </dl>
                            <p className="break-all text-xs text-muted-foreground">
                                {t('Task ID')}: {id}
                            </p>
                            {Number(progress.counts.failed) > 0 && (
                                <Button disabled={busy} onClick={() => void submit(true)}>
                                    {t('Retry failed cards')}
                                </Button>
                            )}
                            {progress.details.items.map((item) => (
                                <div key={item.id} className="border-t py-2 text-sm">
                                    {companyName(item.tenant_id)} · {item.masked_pan} ·{' '}
                                    {t(
                                        reasons[item.error_code] ??
                                            'Transaction synchronization failed',
                                    )}{' '}
                                    · {t('Page')} {item.next_page}
                                </div>
                            ))}
                            <div className="flex gap-2">
                                <Button
                                    variant="secondary"
                                    disabled={page <= 1}
                                    onClick={() => setPage(page - 1)}
                                >
                                    {t('Previous')}
                                </Button>
                                <Button
                                    variant="secondary"
                                    disabled={!progress.details.hasMore}
                                    onClick={() => setPage(page + 1)}
                                >
                                    {t('Next')}
                                </Button>
                            </div>
                        </section>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
