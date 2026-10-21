import { useEffect, useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { MoneyDisplay } from './MoneyDisplay';
import { AdminPagination } from './AdminPagination';
import { supportRequest } from '@/components/support/supportRequest';
import { t, dateTime } from '@/i18n/admin';

export type RechargeAdjustmentHistory = {
    amount: string;
    items: {
        id: string;
        amount: string;
        business_date: string;
        reason: string;
        actor_name: string;
        created_at: string;
    }[];
    page: number;
    pages: number;
    total: number;
};
export function RechargeAdjustmentRecords({
    data,
    onPage,
    disabled = false,
}: {
    data: RechargeAdjustmentHistory;
    onPage: (page: number) => void;
    disabled?: boolean;
}) {
    return (
        <section className="space-y-3">
            <h3 className="font-semibold">
                {t('Display adjustment history')} · {data.total}
            </h3>
            {!data.items.length && (
                <p className="text-sm text-muted-foreground">{t('No source records.')}</p>
            )}
            {data.items.map((entry) => (
                <article key={entry.id} className="space-y-1 rounded-lg border p-3 text-sm">
                    <div className="flex flex-wrap justify-between gap-2">
                        <strong>
                            <MoneyDisplay amount={entry.amount} asset="USDT" />
                        </strong>
                        <span>
                            {t('Business date')}: {entry.business_date}
                        </span>
                    </div>
                    <p className="whitespace-pre-wrap break-words">{entry.reason}</p>
                    <p className="text-muted-foreground">
                        {entry.actor_name} · {dateTime(entry.created_at)}
                    </p>
                </article>
            ))}
            <AdminPagination
                page={data.page}
                pages={data.pages}
                total={data.total}
                disabled={disabled}
                onPage={onPage}
            />
        </section>
    );
}

type Report = RechargeAdjustmentHistory & {
    recordedAmount: string | null;
    adjustedAmount: string | null;
};
const today = () =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
export function OverviewRechargeAdjustmentsDialog({
    canManage,
    onClose,
    onSaved,
}: {
    canManage: boolean;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [report, setReport] = useState<Report | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(today);
    const [reason, setReason] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const url = '/platform/overview/recharge-adjustments';
    useEffect(() => {
        let active = true;
        setReport(null);
        setError('');
        void supportRequest<Report>(`${url}?page=${page}`)
            .then((value) => {
                if (active) setReport(value);
            })
            .catch(() => {
                if (active) setError('Unable to load. Please try again.');
            });
        return () => {
            active = false;
        };
    }, [page, revision]);
    const edit = () => {
        setRequestId(crypto.randomUUID());
        setConfirmed(false);
    };
    const close = () => {
        if (!busy && ((!amount && !reason) || confirm(t('Discard unsaved changes?')))) onClose();
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent className="max-w-3xl" closeDisabled={busy} closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>{t('Actual recharge display adjustments')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'Only changes the actual recharge figures in this overview. Orders, wallets and other reports remain unchanged.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <div className="max-h-[70vh] space-y-5 overflow-y-auto pr-1">
                    {error && (
                        <p role="alert" className="text-sm text-danger">
                            {t(error)}
                        </p>
                    )}
                    {!report && (
                        <Button variant="secondary" onClick={() => setRevision((n) => n + 1)}>
                            {t('Retry')}
                        </Button>
                    )}
                    {report && (
                        <dl className="grid gap-3 rounded-lg border p-3 text-sm sm:grid-cols-3">
                            {[
                                [t('Recorded recharge total'), report.recordedAmount],
                                [t('Cumulative display adjustment'), report.amount],
                                [t('Adjusted recharge total'), report.adjustedAmount],
                            ].map(([label, value]) => (
                                <div key={label}>
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="mt-1 font-semibold">
                                        {value === null ? (
                                            t('Unavailable estimate')
                                        ) : (
                                            <MoneyDisplay amount={value!} asset="USDT" />
                                        )}
                                    </dd>
                                </div>
                            ))}
                        </dl>
                    )}
                    {canManage && (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (busy || !confirmed || !report) return;
                                if (/^-?0*(?:\.0*)?$/.test(amount)) {
                                    setError('Amount must not be zero.');
                                    return;
                                }
                                setBusy(true);
                                setError('');
                                void supportRequest(url, {
                                    amount,
                                    business_date: date,
                                    reason,
                                    confirmed,
                                    request_id: requestId,
                                })
                                    .then(() => {
                                        setAmount('');
                                        setReason('');
                                        edit();
                                        setPage(1);
                                        setRevision((n) => n + 1);
                                        onSaved();
                                    })
                                    .catch(() => setError('Unable to save. Refresh and try again.'))
                                    .finally(() => setBusy(false));
                            }}
                        >
                            <label className="block space-y-1">
                                {t('Adjustment amount (USDT)')}
                                <Input
                                    required
                                    disabled={busy}
                                    inputMode="decimal"
                                    pattern="-?[0-9]{1,18}(\.[0-9]{1,8})?"
                                    value={amount}
                                    onChange={(e) => {
                                        setAmount(e.target.value);
                                        edit();
                                    }}
                                />
                            </label>
                            <label className="block space-y-1">
                                {t('Business date')}
                                <Input
                                    required
                                    disabled={busy}
                                    type="date"
                                    max={today()}
                                    value={date}
                                    onChange={(e) => {
                                        setDate(e.target.value);
                                        edit();
                                    }}
                                />
                            </label>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'Only the lifetime actual recharge total includes display adjustments. Daily and recent totals stay unchanged.',
                                )}
                            </p>
                            <label className="block space-y-1">
                                {t('Adjustment reason')}
                                <textarea
                                    className="min-h-20 w-full rounded-md border bg-background p-2"
                                    required
                                    disabled={busy}
                                    maxLength={2000}
                                    value={reason}
                                    onChange={(e) => {
                                        setReason(e.target.value);
                                        edit();
                                    }}
                                />
                            </label>
                            <p className="text-sm">
                                {t('This adjustment')}: {amount || '—'} {t('USDT')}
                            </p>
                            <label className="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    disabled={busy}
                                    checked={confirmed}
                                    onChange={(e) => setConfirmed(e.target.checked)}
                                />
                                {t(
                                    amount.startsWith('-')
                                        ? 'Confirm this negative display adjustment.'
                                        : 'Confirm this display adjustment.',
                                )}
                            </label>
                            <Button type="submit" disabled={busy || !confirmed || !report}>
                                {t('Record adjustment')}
                            </Button>
                            <p className="text-xs text-muted-foreground">
                                {t(
                                    'History cannot be edited or deleted. Correct an entry by adding an opposite adjustment.',
                                )}
                            </p>
                        </form>
                    )}
                    {report && (
                        <RechargeAdjustmentRecords data={report} onPage={setPage} disabled={busy} />
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
