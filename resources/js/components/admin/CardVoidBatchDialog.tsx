import { useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { displayMoney } from '@/lib/admin-amount';
import { t, dateTime } from '@/i18n/admin';

export type VoidCard = {
    id: string;
    maskedPan: string;
    companyName: string;
    balance: string | null;
};
type Item = {
    id: string;
    masked_pan: string;
    company: string;
    account_id: string;
    status: string;
    stage: string;
    returned: string;
    fee: string;
    error_code: string | null;
};
type Batch = {
    id: string;
    counts: Record<'PENDING' | 'RUNNING' | 'UNKNOWN' | 'SUCCEEDED' | 'FAILED', number>;
    items: Item[];
};
const stages: Record<string, string> = {
    CHECK: 'Checking card',
    FREEZE: 'Freezing card',
    RETURN: 'Returning card balance',
    OVERFLOW: 'Returning overflow balance',
    CANCEL: 'Cancelling card',
    VERIFY: 'Verifying cancellation',
    DONE: 'Card voided',
};
const statuses: Record<string, string> = {
    PENDING: 'Pending',
    RUNNING: 'Processing',
    UNKNOWN: 'Awaiting confirmation',
    SUCCEEDED: 'Card voided',
    FAILED: 'Failed',
};
const errors: Record<string, string> = {
    CARD_VOID_PRECISION: 'The provider balance requires manual review before voiding.',
    CARD_OPERATION_OUTSTANDING: 'Wait for the current card operation to finish.',
    CARD_VOID_BALANCE_REMAINS: 'Card balance remains. Cancellation has not been submitted.',
    CARD_VOID_PROVIDER_REJECTED: 'The provider rejected this card voiding step.',
};
async function api<T>(path: string, body?: object, signal?: AbortSignal): Promise<T> {
    const csrf = document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content;
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='));
    const token = cookie ? decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)) : undefined;
    if (body && !token && !csrf)
        throw new Error('Session expired. Refresh the page and sign in again.');
    const response = await fetch(`/platform/card-void-batches${path}`, {
        method: body ? 'POST' : 'GET',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(body
                ? {
                      'Content-Type': 'application/json',
                      ...(token ? { 'X-XSRF-TOKEN': token } : { 'X-CSRF-TOKEN': csrf! }),
                  }
                : {}),
        },
        body: body ? JSON.stringify(body) : undefined,
        signal: AbortSignal.any([AbortSignal.timeout(90000), ...(signal ? [signal] : [])]),
    });
    if (!response.ok) {
        if ([401, 419].includes(response.status))
            throw new Error('Session expired. Refresh the page and sign in again.');
        throw new Error('Card voiding could not be completed. Check the task before trying again.');
    }
    return response.json() as Promise<T>;
}

export function CardVoidBatchDialog({
    cards,
    company,
    onClose,
    onCompleted,
}: {
    cards: VoidCard[] | null;
    company?: string;
    onClose: () => void;
    onCompleted: () => void;
}) {
    const [batch, setBatch] = useState<Batch | null>(null);
    const [recent, setRecent] = useState<{ id: string; created_at: string }[]>([]);
    const [running, setRunning] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const request = useRef(crypto.randomUUID());
    const mounted = useRef(true);
    const controller = useRef(new AbortController());
    const completed = useRef(onCompleted);
    completed.current = onCompleted;
    useEffect(() => {
        mounted.current = true;
        if (!cards)
            api<{ items: typeof recent }>('', undefined, controller.current.signal)
                .then((data) => setRecent(data.items))
                .catch(() => setError('Card tasks could not be loaded.'));
        return () => {
            mounted.current = false;
            controller.current.abort();
        };
        // Each opening owns one request and one explicit card selection.
    }, []);
    useEffect(() => {
        if (!running || !batch) return;
        const timer = setTimeout(() => {
            api<Batch>(`/${batch.id}/advance`, {}, controller.current.signal)
                .then((data) => {
                    if (!mounted.current) return;
                    setBatch(data);
                    if (!data.counts.PENDING && !data.counts.RUNNING) {
                        setRunning(false);
                        completed.current();
                    }
                })
                .catch(() => {
                    if (!mounted.current) return;
                    setRunning(false);
                    setError(
                        'Card voiding could not be completed. Check the task before trying again.',
                    );
                });
        }, 1500);
        return () => clearTimeout(timer);
    }, [running, batch]);
    async function start() {
        if (!cards || busy) return;
        setBusy(true);
        setError('');
        try {
            const result = await api<{ id: string }>(
                '',
                {
                    card_ids: cards.map((card) => card.id),
                    tenant_id: company ?? null,
                    request_id: request.current,
                    confirmed: true,
                },
                controller.current.signal,
            );
            const data = await api<Batch>(`/${result.id}`, undefined, controller.current.signal);
            if (mounted.current) {
                setBatch(data);
                setRunning(true);
            }
        } catch (err) {
            if (mounted.current)
                setError(
                    err instanceof Error &&
                        err.message === 'Session expired. Refresh the page and sign in again.'
                        ? err.message
                        : 'Card voiding could not be completed. Check the task before trying again.',
                );
        } finally {
            if (mounted.current) setBusy(false);
        }
    }
    async function load(id: string) {
        setBusy(true);
        setError('');
        try {
            const data = await api<Batch>(`/${id}`, undefined, controller.current.signal);
            if (mounted.current) {
                setBatch(data);
                setRunning(false);
            }
        } catch {
            if (mounted.current) setError('Card tasks could not be loaded.');
        } finally {
            if (mounted.current) setBusy(false);
        }
    }
    async function retry() {
        if (!batch) return;
        setBusy(true);
        setError('');
        try {
            const data = await api<Batch>(
                `/${batch.id}/retry`,
                { confirmed: true },
                controller.current.signal,
            );
            if (mounted.current) {
                setBatch(data);
                setRunning(true);
            }
        } catch {
            if (mounted.current)
                setError(
                    'Card voiding could not be completed. Check the task before trying again.',
                );
        } finally {
            if (mounted.current) setBusy(false);
        }
    }
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DialogContent
                presentation="drawer"
                className="max-w-5xl [&>button:last-child]:z-30"
                closeLabel={t('Close')}
            >
                <DialogHeader>
                    <DialogTitle>{t(cards ? 'Void cards' : 'Card voiding tasks')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            batch
                                ? 'Closing pauses further steps. Reopen the task to continue.'
                                : cards
                                  ? 'Return all card and overflow balances to each cardholder’s USDT wallet, then permanently cancel the cards. Provider fees reduce the amount received.'
                                  : 'Open a task to view its results or continue processing.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-sm text-destructive">
                        {t(error)}
                    </p>
                )}
                {!batch && cards && (
                    <>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr>
                                        <th className="p-2 text-left">{t('Company')}</th>
                                        <th className="p-2 text-left">{t('Card')}</th>
                                        <th className="p-2 text-right">{t('Current balance')}</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {cards.map((card) => (
                                        <tr key={card.id}>
                                            <td className="p-2">{card.companyName}</td>
                                            <td className="p-2">{card.maskedPan}</td>
                                            <td className="p-2 text-right">
                                                {card.balance === null
                                                    ? t('Not available')
                                                    : `${displayMoney(card.balance)} USD`}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                        <div className="flex justify-end gap-2">
                            <Button variant="secondary" disabled={busy} onClick={onClose}>
                                {t('Cancel')}
                            </Button>
                            <Button
                                variant="destructive"
                                disabled={busy}
                                onClick={() => void start()}
                            >
                                {t(busy ? 'Processing' : 'Confirm refund and void cards')}
                            </Button>
                        </div>
                    </>
                )}
                {!batch && !cards && (
                    <div className="space-y-2">
                        {recent.length === 0 && (
                            <p className="text-sm text-muted-foreground">
                                {t('No card voiding tasks')}
                            </p>
                        )}
                        {recent.map((item) => (
                            <Button
                                key={item.id}
                                variant="secondary"
                                disabled={busy}
                                onClick={() => void load(item.id)}
                            >
                                {dateTime(item.created_at)}
                            </Button>
                        ))}
                    </div>
                )}
                {batch && (
                    <>
                        <div className="flex flex-wrap gap-3 text-sm">
                            {Object.entries(batch.counts).map(([status, count]) => (
                                <span key={status}>
                                    {t(statuses[status] ?? status)}: {count}
                                </span>
                            ))}
                        </div>
                        <div className="flex flex-wrap gap-2">
                            {batch.counts.PENDING + batch.counts.RUNNING + batch.counts.UNKNOWN >
                                0 && (
                                <Button
                                    disabled={busy || running}
                                    onClick={() => {
                                        setError('');
                                        setRunning(true);
                                    }}
                                >
                                    {t(running ? 'Processing' : 'Continue card voiding')}
                                </Button>
                            )}
                            {running && (
                                <Button variant="secondary" onClick={() => setRunning(false)}>
                                    {t('Pause')}
                                </Button>
                            )}
                            {!running && batch.counts.FAILED > 0 && (
                                <Button
                                    variant="destructive"
                                    disabled={busy}
                                    onClick={() => void retry()}
                                >
                                    {t('Confirm retry of failed cards')}
                                </Button>
                            )}
                            {!running && (
                                <Button
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() => void load(batch.id)}
                                >
                                    {t('Refresh')}
                                </Button>
                            )}
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full text-sm">
                                <thead>
                                    <tr>
                                        {[
                                            'Company',
                                            'Account ID',
                                            'Card',
                                            'Status',
                                            'Amount returned',
                                            'Fee',
                                        ].map((label) => (
                                            <th
                                                key={label}
                                                className="whitespace-nowrap p-2 text-left"
                                            >
                                                {t(label)}
                                            </th>
                                        ))}
                                    </tr>
                                </thead>
                                <tbody>
                                    {batch.items.map((item) => (
                                        <tr key={item.id} className="border-t">
                                            <td className="p-2">{item.company}</td>
                                            <td className="p-2">{item.account_id}</td>
                                            <td className="whitespace-nowrap p-2">
                                                {item.masked_pan}
                                            </td>
                                            <td className="min-w-44 p-2">
                                                <div>{t(statuses[item.status] ?? item.status)}</div>
                                                <div className="text-xs text-muted-foreground">
                                                    {t(stages[item.stage] ?? item.stage)}
                                                </div>
                                                {item.error_code && (
                                                    <div className="text-xs text-destructive">
                                                        {t(
                                                            errors[item.error_code] ??
                                                                'Review this card before continuing.',
                                                        )}
                                                    </div>
                                                )}
                                            </td>
                                            <td className="whitespace-nowrap p-2">
                                                {displayMoney(item.returned)} USDT
                                            </td>
                                            <td className="whitespace-nowrap p-2">
                                                {displayMoney(item.fee)} USDT
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </>
                )}
            </DialogContent>
        </Dialog>
    );
}
