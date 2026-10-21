import { useEffect, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { AdminPagination } from './AdminPagination';
import { supportRequest } from '@/components/support/supportRequest';
import { t, dateTime } from '@/i18n/admin';
import type { PlatformUser } from './UserManagement';

type Entry = {
    source?: 'user' | 'partner';
    partner_id?: string | null;
    id: string;
    amount: string;
    business_date: string;
    note: string;
    reverses_id: string | null;
    reversed: boolean;
    order_backed: boolean;
    created_at: string;
};
type Data = { amount: string; items: Entry[]; page: number; pages: number; total: number };
const today = () =>
    new Intl.DateTimeFormat('en-CA', {
        timeZone: 'Asia/Shanghai',
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
    }).format(new Date());
export function UserAdvancesPanel({ user, onSaved }: { user: PlatformUser; onSaved?: () => void }) {
    const url = `/platform/tenants/${user.companyId}/users/${user.id}/advances`;
    const [data, setData] = useState<Data | null>(null);
    const [page, setPage] = useState(1);
    const [revision, setRevision] = useState(0);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [amount, setAmount] = useState('');
    const [date, setDate] = useState(today);
    const [note, setNote] = useState('');
    const [reversal, setReversal] = useState<Entry | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    useEffect(() => {
        let active = true;
        setData(null);
        supportRequest<Data>(`${url}?page=${page}`)
            .then((value) => {
                if (active) setData(value);
            })
            .catch(() => {
                if (active) setError('Unable to load. Please try again.');
            });
        return () => {
            active = false;
        };
    }, [url, page, revision]);
    const resetRequest = () => {
        setRequestId(crypto.randomUUID());
        setConfirmed(false);
    };
    return (
        <section className="min-h-0 flex-1 space-y-4 overflow-y-auto p-4" data-detail-body>
            <h3>
                {t('Advance amount')}: {data?.amount ?? '—'} USDT
            </h3>
            <p className="text-sm text-muted-foreground">
                {t(
                    'Signed user advances are administrative records. They do not move wallet funds.',
                )}
            </p>
            {error && <p role="alert">{t(error)}</p>}
            {!data && (
                <Button
                    variant="secondary"
                    onClick={() => {
                        setError('');
                        setRevision((n) => n + 1);
                    }}
                >
                    {t('Retry')}
                </Button>
            )}
            <form
                className="grid gap-3"
                onSubmit={(event) => {
                    event.preventDefault();
                    if (busy || !confirmed) return;
                    setBusy(true);
                    setError('');
                    void supportRequest(url, {
                        amount,
                        business_date: date,
                        note,
                        reverses_id: reversal?.id ?? null,
                        confirmed,
                        request_id: requestId,
                    })
                        .then(() => {
                            setAmount('');
                            setNote('');
                            setReversal(null);
                            resetRequest();
                            onSaved?.();
                            setRevision((n) => n + 1);
                        })
                        .catch(() => setError('Unable to save. Refresh and try again.'))
                        .finally(() => setBusy(false));
                }}
            >
                {reversal && (
                    <p>
                        {t('Reversal')} · {reversal.amount} USDT
                    </p>
                )}
                <label>
                    {t('Amount')}
                    <Input
                        required
                        disabled={busy || !!reversal}
                        inputMode="decimal"
                        pattern="-?[0-9]{1,18}(\.[0-9]{1,8})?"
                        value={amount}
                        onChange={(e) => {
                            setAmount(e.target.value);
                            resetRequest();
                        }}
                    />
                </label>
                <label>
                    {t('Business date')}
                    <Input
                        required
                        disabled={busy}
                        type="date"
                        value={date}
                        onChange={(e) => {
                            setDate(e.target.value);
                            resetRequest();
                        }}
                    />
                </label>
                <label>
                    {t('Note')}
                    <Input
                        required
                        disabled={busy}
                        maxLength={2000}
                        value={note}
                        onChange={(e) => {
                            setNote(e.target.value);
                            resetRequest();
                        }}
                    />
                </label>
                <label className="flex items-center gap-2">
                    <input
                        type="checkbox"
                        disabled={busy}
                        checked={confirmed}
                        onChange={(e) => setConfirmed(e.target.checked)}
                    />
                    {t(
                        amount.startsWith('-')
                            ? 'Confirm negative advance offset for this user.'
                            : 'Confirm this user advance entry or reversal.',
                    )}
                </label>
                <div className="flex gap-2">
                    <Button disabled={busy || !confirmed || !data} type="submit">
                        {t(reversal ? 'Reversal' : 'Record entry')}
                    </Button>
                    {reversal && (
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={busy}
                            onClick={() => {
                                setReversal(null);
                                setAmount('');
                                resetRequest();
                            }}
                        >
                            {t('Cancel')}
                        </Button>
                    )}
                </div>
            </form>
            {data?.items.map((entry) => (
                <article key={entry.id} className="space-y-1 border-t py-3">
                    <p>
                        {entry.reverses_id ? t('Reversal') : t('Advance amount')} ·{' '}
                        {entry.reverses_id ? `− (${entry.amount})` : entry.amount} USDT ·{' '}
                        {entry.business_date}
                    </p>
                    <p className="break-words">{entry.note}</p>
                    <p>{dateTime(entry.created_at)}</p>
                    {entry.source === 'partner' ? (
                        <a
                            className="underline"
                            href={`/platform/partners?tenant=${encodeURIComponent(user.companyId)}&partner=${encodeURIComponent(entry.partner_id ?? '')}`}
                        >
                            {t('View report')}
                        </a>
                    ) : entry.order_backed ? (
                        <p>
                            {t(
                                'Change the deposit receipt classification to reverse this advance.',
                            )}
                        </p>
                    ) : (
                        !entry.reversed &&
                        !entry.reverses_id && (
                            <Button
                                variant="secondary"
                                disabled={busy}
                                onClick={() => {
                                    setReversal(entry);
                                    setAmount(entry.amount);
                                    setDate(today());
                                    setNote('');
                                    resetRequest();
                                }}
                            >
                                {t('Reversal')}
                            </Button>
                        )
                    )}
                </article>
            ))}
            {data && (
                <AdminPagination
                    page={page}
                    pages={data.pages}
                    total={data.total}
                    disabled={busy}
                    onPage={setPage}
                />
            )}
        </section>
    );
}
