import { adminAssetLabel } from '@/lib/admin-asset-label';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useEffect, useState, type RefObject } from 'react';
import { Dialog, DialogHeader, DialogTitle, DialogDescription } from '@/components/ui/dialog';
import { DetailDrawerContent } from './DetailDrawer';
import { readEditorResponse } from './editor-response';
import { Button } from '@/components/ui/button';
import { MoneyDisplay } from './MoneyDisplay';
import { t } from '@/i18n/admin';
import { clientI18n } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
import {
    fundsLocation,
    initialFundsFilters,
    type FundsFilters,
    type FundsTarget,
} from './user-funds-state';

type FundsReport = {
    company: { name: string };
    user: { displayName: string | null; email: string | null };
    timezone: string;
    accounts: { asset: string; type: string; balance: string }[];
    events: { value: string; label: string }[];
    rows: {
        page: number;
        lastPage: number;
        total: number;
        items: {
            id: string;
            asset: string;
            kind: string;
            event: string;
            time: string;
            movements: { id: string; account: string; amount: string }[];
            details: {
                reason: string;
                reference: string | null;
                counterparty: null | {
                    role: string;
                    email: string | null;
                    accountId: string | null;
                };
            };
        }[];
    };
};
export function UserFundsDrawer({
    embedded = false,
    target,
    trigger,
    onClose,
}: {
    embedded?: boolean;
    target: FundsTarget;
    trigger: RefObject<HTMLElement | null>;
    onClose: () => void;
}) {
    const [filters, setFilters] = useState(() =>
        embedded ? { asset: '', event: '', from: '', to: '', page: 1 } : initialFundsFilters(),
    );
    const [draft, setDraft] = useState(filters);
    const [report, setReport] = useState<FundsReport | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState('');
    const [retry, setRetry] = useState(0);
    useEffect(() => {
        if (embedded) return;
        const sync = () => {
            const next = initialFundsFilters();
            setFilters(next);
            setDraft(next);
        };
        window.addEventListener('popstate', sync);
        return () => window.removeEventListener('popstate', sync);
    }, [embedded]);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setError('');
        const params = new URLSearchParams();
        for (const [key, value] of Object.entries(filters))
            if (value) params.set(key, String(value));
        void fetch(
            `/platform/tenants/${encodeURIComponent(target.company)}/users/${encodeURIComponent(target.user)}/funds?${params}`,
            {
                credentials: 'same-origin',
                cache: 'no-store',
                signal: controller.signal,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            },
        )
            .then(async (response) => {
                const data = await readEditorResponse(response);
                if (!response.ok || !data.user || !data.rows)
                    throw new Error(t('Unable to load. Please retry.'));
                if (!controller.signal.aborted) setReport(data as FundsReport);
            })
            .catch(() => {
                if (!controller.signal.aborted) setError(t('Unable to load. Please retry.'));
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [target.company, target.user, filters, retry]);
    const visit = (next: FundsFilters) => {
        if (!embedded) fundsLocation(target, next);
        setFilters(next);
        setDraft(next);
    };
    const inputClass = 'h-9 w-full rounded-md border bg-surface px-2 text-sm';
    const content = (
        <>
            {!embedded && (
                <DialogHeader className="mb-0 shrink-0 border-b p-4 pr-14">
                    <DialogTitle>
                        {t('User fund flows')}
                        {report ? ` · ${report.user.displayName || '—'}` : ''}
                    </DialogTitle>
                    <DialogDescription>
                        {report
                            ? `${report.company.name} · ${report.user.email || '—'} · ${report.timezone}`
                            : t('Loading…')}
                    </DialogDescription>
                </DialogHeader>
            )}
            <div
                className="min-h-0 flex-1 space-y-4 overflow-y-auto overscroll-contain p-4"
                aria-busy={loading}
            >
                {report && (
                    <>
                        <section
                            className="grid grid-cols-1 gap-x-5 gap-y-2 sm:grid-cols-2 lg:grid-cols-3"
                            aria-label={t('Current account balances')}
                        >
                            {report.accounts.map((a) => (
                                <div
                                    key={`${a.asset}:${a.type}`}
                                    className="flex min-w-0 flex-wrap items-baseline gap-x-1 text-sm"
                                >
                                    <span className="text-xs text-muted-foreground">
                                        {t(a.type)}：
                                    </span>
                                    <span className="whitespace-nowrap">
                                        <MoneyDisplay amount={a.balance} asset={a.asset} />
                                    </span>
                                </div>
                            ))}
                        </section>
                        <form
                            className="flex flex-wrap items-end gap-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (draft.from && draft.to && draft.from > draft.to) {
                                    setError(t('End date must not precede start date.'));
                                    return;
                                }
                                visit({ ...draft, page: 1 });
                            }}
                        >
                            <label className="min-w-32 flex-1 basis-32 space-y-1 text-sm">
                                {t('Currency')}
                                <select
                                    className={inputClass}
                                    value={draft.asset}
                                    onChange={(e) => setDraft({ ...draft, asset: e.target.value })}
                                >
                                    <option value="">{t('All currencies')}</option>
                                    {[...new Set(report.accounts.map((a) => a.asset))].map(
                                        (asset) => (
                                            <option key={asset} value={asset}>
                                                {adminAssetLabel(asset)}
                                            </option>
                                        ),
                                    )}
                                </select>
                            </label>
                            <label className="min-w-32 flex-1 basis-32 space-y-1 text-sm">
                                {t('Type')}
                                <select
                                    className={inputClass}
                                    value={draft.event}
                                    onChange={(e) => setDraft({ ...draft, event: e.target.value })}
                                >
                                    <option value="">{t('All types')}</option>
                                    {report.events.map((event) => (
                                        <option key={event.value} value={event.value}>
                                            {t(event.label)}
                                        </option>
                                    ))}
                                </select>
                            </label>
                            <label className="min-w-32 flex-1 basis-32 space-y-1 text-sm">
                                {t('Start date')}
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={draft.from}
                                    onChange={(e) => setDraft({ ...draft, from: e.target.value })}
                                />
                            </label>
                            <label className="min-w-32 flex-1 basis-32 space-y-1 text-sm">
                                {t('End date')}
                                <input
                                    type="date"
                                    className={inputClass}
                                    value={draft.to}
                                    min={draft.from || undefined}
                                    onChange={(e) => setDraft({ ...draft, to: e.target.value })}
                                />
                            </label>
                            <div className="flex shrink-0 gap-2 [&>button]:shrink-0 [&>button]:whitespace-nowrap">
                                <Button disabled={loading}>{t('Apply')}</Button>
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={loading}
                                    onClick={() =>
                                        visit({
                                            asset: '',
                                            event: '',
                                            from: '',
                                            to: '',
                                            page: 1,
                                        })
                                    }
                                >
                                    {t('Reset filters')}
                                </Button>
                            </div>
                        </form>
                    </>
                )}
                {loading ? (
                    <p role="status">{t('Loading…')}</p>
                ) : error ? (
                    <div role="alert" className="space-y-2">
                        <OperationFeedback>{error}</OperationFeedback>
                        <Button onClick={() => setRetry((n) => n + 1)}>{t('Retry')}</Button>
                    </div>
                ) : (
                    report && (
                        <>
                            {report.rows.items.length === 0 ? (
                                <p>
                                    {filters.asset || filters.event || filters.from || filters.to
                                        ? t('No matching records.')
                                        : t('This user has no posted fund flows.')}
                                </p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <table className="w-full text-left text-sm">
                                        <thead className="border-b bg-muted/30">
                                            <tr>
                                                {['Time', 'Type / reason', 'Account movements'].map(
                                                    (label) => (
                                                        <th
                                                            key={label}
                                                            className="px-3 py-2.5 font-medium"
                                                        >
                                                            {t(label)}
                                                        </th>
                                                    ),
                                                )}
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {report.rows.items.map((row) => (
                                                <tr
                                                    key={row.id}
                                                    className="border-b last:border-0 align-top"
                                                >
                                                    <td className="whitespace-nowrap px-3 py-2.5">
                                                        {new Intl.DateTimeFormat(
                                                            clientI18n.language,
                                                            {
                                                                timeZone: report.timezone,
                                                                dateStyle: 'medium',
                                                                timeStyle: 'short',
                                                            },
                                                        ).format(new Date(row.time))}
                                                    </td>
                                                    <td className="min-w-64 px-3 py-2.5">
                                                        <p className="font-medium">{t(row.kind)}</p>
                                                        <p className="mt-1 text-xs text-muted-foreground">
                                                            {t(row.details.reason)}
                                                        </p>
                                                        {row.details.counterparty && (
                                                            <p className="mt-1 break-all">
                                                                {t(
                                                                    row.details.counterparty
                                                                        .role === 'recipient'
                                                                        ? 'Recipient'
                                                                        : 'Sender',
                                                                )}
                                                                :{' '}
                                                                {row.details.counterparty.email ||
                                                                    row.details.counterparty
                                                                        .accountId ||
                                                                    '—'}
                                                            </p>
                                                        )}
                                                        <details className="mt-2 text-xs text-muted-foreground">
                                                            <summary className="cursor-pointer">
                                                                {t('References')}
                                                            </summary>
                                                            <p className="break-all">
                                                                {t('Ledger entry reference')}:{' '}
                                                                {row.id}
                                                            </p>
                                                            <p className="break-all">
                                                                {t('Business reference')}:{' '}
                                                                {row.details.reference || '—'}
                                                            </p>
                                                            <p>{row.event}</p>
                                                        </details>
                                                    </td>
                                                    <td className="min-w-56 px-3 py-2.5">
                                                        {row.movements.map((move) => (
                                                            <div
                                                                key={move.id}
                                                                className="mb-1 flex justify-between gap-3"
                                                            >
                                                                <span className="text-muted-foreground">
                                                                    {t(move.account)}
                                                                </span>
                                                                <span className="whitespace-nowrap font-medium tabular-nums">
                                                                    {move.amount.startsWith('-')
                                                                        ? ''
                                                                        : '+'}
                                                                    {exactAmount(move.amount)}{' '}
                                                                    {adminAssetLabel(row.asset)}
                                                                </span>
                                                            </div>
                                                        ))}
                                                    </td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </>
                    )
                )}
            </div>
            {report && (
                <div className="flex shrink-0 items-center justify-between border-t p-4">
                    <Button
                        variant="secondary"
                        disabled={loading || filters.page <= 1}
                        onClick={() => visit({ ...filters, page: filters.page - 1 })}
                    >
                        {t('Previous')}
                    </Button>
                    <span className="text-sm">
                        {report.rows.page} / {report.rows.lastPage} · {t('Total records')}:{' '}
                        {report.rows.total}
                    </span>
                    <Button
                        variant="secondary"
                        disabled={loading || filters.page >= report.rows.lastPage}
                        onClick={() => visit({ ...filters, page: filters.page + 1 })}
                    >
                        {t('Next')}
                    </Button>
                </div>
            )}
        </>
    );
    if (embedded) return <div className="flex min-h-0 flex-1 flex-col">{content}</div>;
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) onClose();
            }}
        >
            <DetailDrawerContent
                className="p-0"
                closeLabel={t('Close')}
                onCloseAutoFocus={(event) => {
                    if (trigger.current?.isConnected) {
                        event.preventDefault();
                        trigger.current.focus();
                    }
                }}
            >
                {content}
            </DetailDrawerContent>
        </Dialog>
    );
}
