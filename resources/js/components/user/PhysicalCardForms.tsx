import { useEffect, useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t } from '@/i18n';

async function send(url: string, data: Record<string, unknown>) {
    const token = document.cookie
        .split('; ')
        .find((x) => x.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const response = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
        },
        body: JSON.stringify(data),
    });
    const result: unknown = await response.json();
    if (typeof result !== 'object' || result === null) throw new Error('Request failed.');
    const payload = result as Record<string, unknown>;
    const errors =
        typeof payload.errors === 'object' && payload.errors !== null
            ? Object.values(payload.errors)
                  .flat()
                  .filter((value): value is string => typeof value === 'string')
                  .join(' ')
            : '';
    const error =
        typeof payload.error === 'object' && payload.error !== null
            ? (payload.error as Record<string, unknown>).message
            : null;
    if (!response.ok)
        throw new Error(typeof error === 'string' ? error : errors || 'Request failed.');
    if (typeof payload.status !== 'string') throw new Error('Request failed.');
    return {
        status: payload.status,
        id: typeof payload.id === 'string' ? payload.id : '',
        fields: Object.fromEntries(
            typeof payload.fields === 'object' && payload.fields !== null
                ? Object.entries(payload.fields).filter(
                      (entry): entry is [string, string] => typeof entry[1] === 'string',
                  )
                : [],
        ),
    };
}
const recipientFields = [
    ['recipientFirstName', 'Recipient first name', 40],
    ['recipientLastName', 'Recipient last name', 40],
    ['mobilePrefix', 'Phone country code', 8],
    ['mobile', 'Phone number', 11],
    ['country', 'Country code', 2],
    ['state', 'State or province', 50],
    ['city', 'City', 50],
    ['addressLine1', 'Address line 1', 50],
    ['addressLine2', 'Address line 2', 50],
    ['addressLine3', 'Address line 3', 50],
    ['postalCode', 'Postal code', 12],
] as const;

export function CardRecipientForm({
    applicationId,
    saved,
    onReady,
}: {
    applicationId: string;
    saved?: { id: string; status: string } | null;
    onReady: (id: string, summary: string) => void;
}) {
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const [fields, setFields] = useState<Record<string, string>>({});
    const [state, setState] = useState(saved?.status === 'FAILED' ? '' : (saved?.status ?? ''));
    const [id, setId] = useState(saved?.id ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const summary = recipientFields
        .map(([key]) => fields[key])
        .filter(Boolean)
        .join(', ');
    async function inspect() {
        if (!id || busy) return;
        setBusy(true);
        try {
            const result = await send(`/cards/recipients/${id}/inspect`, {});
            setState(result.status);
            setFields(result.fields);
            if (result.status === 'READY')
                onReady(
                    result.id,
                    recipientFields
                        .map(([key]) => result.fields[key])
                        .filter(Boolean)
                        .join(', '),
                );
        } catch (e) {
            setError(e instanceof Error ? e.message : t('Request failed.'));
        } finally {
            setBusy(false);
        }
    }
    if (state === 'READY' || state === 'UNKNOWN' || state === 'SUBMITTING')
        return (
            <div className="space-y-2 p-4">
                <p>
                    {t(
                        state === 'READY'
                            ? 'Recipient saved for this application.'
                            : 'Recipient creation could not be confirmed. Do not submit again.',
                    )}
                </p>
                <p className="text-sm">{summary}</p>
                <Button
                    type="button"
                    disabled={busy}
                    onClick={() => {
                        void inspect();
                    }}
                >
                    {t('Review recipient and refresh status')}
                </Button>
                {error && <p role="alert">{error}</p>}
            </div>
        );
    return (
        <section className="space-y-3 rounded-xl border p-4">
            <h3 className="font-semibold">{t('Physical card recipient')}</h3>
            <div className="grid gap-3 sm:grid-cols-2">
                {recipientFields.map(([key, label, max]) => (
                    <label key={key} className="text-sm">
                        {t(label)}
                        <Input
                            value={fields[key] ?? ''}
                            maxLength={max}
                            autoComplete="off"
                            onChange={(e) => setFields({ ...fields, [key]: e.target.value })}
                        />
                    </label>
                ))}
            </div>
            {error && (
                <p role="alert" className="text-danger">
                    {error}
                </p>
            )}
            {state === 'FAILED' && (
                <Button
                    type="button"
                    onClick={() => {
                        setState('');
                        setRequestId(crypto.randomUUID());
                        setError('');
                    }}
                >
                    {t('Correct recipient details')}
                </Button>
            )}
            <Button
                type="button"
                disabled={busy || state === 'FAILED'}
                onClick={() => {
                    void (async () => {
                        setBusy(true);
                        setError('');
                        try {
                            const result = await send('/cards/recipients', {
                                ...fields,
                                request_id: requestId,
                                cardholder_application_id: applicationId,
                            });
                            setState(result.status);
                            setId(result.id);
                            if (result.status === 'READY')
                                onReady(
                                    result.id,
                                    recipientFields
                                        .map(([key]) => fields[key])
                                        .filter(Boolean)
                                        .join(', '),
                                );
                            else if (result.status === 'FAILED')
                                setError(
                                    t(
                                        'Recipient details were rejected. Close and reopen to correct them.',
                                    ),
                                );
                        } catch (e) {
                            setError(e instanceof Error ? e.message : t('Request failed.'));
                        } finally {
                            setBusy(false);
                        }
                    })();
                }}
            >
                {t('Save recipient')}
            </Button>
        </section>
    );
}

export function PhysicalCardActivation({
    cardId,
    status,
}: {
    cardId: string;
    status?: string | null;
}) {
    const [open, setOpen] = useState(false);
    const [awaiting, setAwaiting] = useState(false);
    const blocked = awaiting || ['PROCESSING', 'UNKNOWN', 'SUCCEEDED'].includes(status ?? '');
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const [expiry, setExpiry] = useState('');
    const [pin, setPin] = useState('');
    const [confirmation, setConfirmation] = useState('');
    const [password, setPassword] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    useEffect(() => {
        const hide = () => {
            if (document.hidden) {
                setPin('');
                setConfirmation('');
                setPassword('');
                setOpen(false);
            }
        };
        document.addEventListener('visibilitychange', hide);
        return () => document.removeEventListener('visibilitychange', hide);
    }, []);
    const clear = () => {
        setPin('');
        setConfirmation('');
        setPassword('');
    };
    return (
        <section className="space-y-3 p-4">
            <Button
                type="button"
                disabled={busy || blocked}
                onClick={() => {
                    clear();
                    setOpen(!open);
                }}
            >
                {t('Activate physical card')}
            </Button>
            <Button
                type="button"
                disabled={busy}
                onClick={() => {
                    void (async () => {
                        setBusy(true);
                        try {
                            const result = await send(`/cards/${cardId}/activation/sync`, {});
                            setMessage(
                                t(
                                    result.status === 'SUCCEEDED'
                                        ? 'Physical card activated.'
                                        : 'Activation is awaiting confirmation. Refresh card status.',
                                ),
                            );
                            router.reload();
                        } catch (error) {
                            setMessage(
                                error instanceof Error ? error.message : t('Request failed.'),
                            );
                        } finally {
                            setBusy(false);
                        }
                    })();
                }}
            >
                {t('Refresh card status')}
            </Button>
            {open && (
                <form
                    className="space-y-3"
                    autoComplete="off"
                    onSubmit={(e) => {
                        e.preventDefault();
                        void (async () => {
                            setBusy(true);
                            setMessage('');
                            try {
                                const result = await send(`/cards/${cardId}/activate`, {
                                    request_id: requestId,
                                    expiration_date: expiry,
                                    pin,
                                    pin_confirmation: confirmation,
                                    current_password: password,
                                    confirmed,
                                });
                                setMessage(
                                    t(
                                        result.status === 'SUCCEEDED'
                                            ? 'Physical card activated.'
                                            : result.status === 'FAILED'
                                              ? 'Activation was rejected.'
                                              : 'Activation is awaiting confirmation. Refresh card status.',
                                    ),
                                );
                                setAwaiting(result.status !== 'FAILED');
                                if (result.status === 'FAILED') setRequestId(crypto.randomUUID());
                                setOpen(false);
                                router.reload();
                            } catch (error) {
                                setMessage(
                                    error instanceof Error ? error.message : t('Request failed.'),
                                );
                            } finally {
                                clear();
                                setBusy(false);
                            }
                        })();
                    }}
                >
                    <label>
                        {t('Card expiry (MM/YY)')}
                        <Input
                            required
                            value={expiry}
                            maxLength={5}
                            onChange={(e) => setExpiry(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('Card PIN')}
                        <Input
                            required
                            type="password"
                            autoComplete="new-password"
                            maxLength={8}
                            value={pin}
                            onChange={(e) => setPin(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('Confirm card PIN')}
                        <Input
                            required
                            type="password"
                            autoComplete="new-password"
                            maxLength={8}
                            value={confirmation}
                            onChange={(e) => setConfirmation(e.target.value)}
                        />
                    </label>
                    <label>
                        {t('Current password')}
                        <Input
                            required
                            type="password"
                            autoComplete="current-password"
                            value={password}
                            onChange={(e) => setPassword(e.target.value)}
                        />
                    </label>
                    <label className="flex gap-2">
                        <input
                            required
                            type="checkbox"
                            checked={confirmed}
                            onChange={(e) => setConfirmed(e.target.checked)}
                        />
                        {t('I have received this physical card and want to activate it.')}
                    </label>
                    <Button disabled={busy} type="submit">
                        {t('Confirm activation')}
                    </Button>
                </form>
            )}
            {message && <p role="status">{message}</p>}
        </section>
    );
}
