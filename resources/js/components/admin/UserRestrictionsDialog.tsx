import { useEffect, useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { supportRequest, SupportRequestError } from '@/components/support/supportRequest';
import { t } from '@/i18n/admin';

const fields = {
    withdrawal_blocked: 'Block withdrawals',
    deposit_refund_blocked: 'Block deposit refund requests',
    card_transfer_blocked: 'Block card transfers',
    wallet_transfer_blocked: 'Block account transfers',
} as const;
type Settings = Record<keyof typeof fields, boolean> & { revision: number };
export function UserRestrictionsDialog({
    user,
    onClose,
}: {
    user: {
        id: string;
        companyId: string;
        companyName: string;
        accountId: string;
        email: string | null;
    };
    onClose: () => void;
}) {
    const [original, setOriginal] = useState<Settings | null>(null);
    const [value, setValue] = useState<Settings | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [requestId, setRequestId] = useState(() => crypto.randomUUID());
    const url = `/platform/tenants/${user.companyId}/users/${user.id}/restrictions`;
    useEffect(() => {
        let active = true;
        supportRequest<Settings>(url)
            .then((settings) => {
                if (active) {
                    setOriginal(settings);
                    setValue(settings);
                }
            })
            .catch(() => {
                if (active) setError('Unable to load. Please try again.');
            });
        return () => {
            active = false;
        };
    }, [url]);
    const dirty = JSON.stringify(value) !== JSON.stringify(original);
    const close = () => {
        if (!busy && (!dirty || confirm(t('Discard unsaved changes?')))) onClose();
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent closeDisabled={busy} closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>{t('Operation restrictions')}</DialogTitle>
                </DialogHeader>
                <p className="text-sm break-all">
                    {user.companyName} · {user.accountId} · {user.email}
                </p>
                {error && (
                    <p role="alert" className="text-sm text-red-700">
                        {t(error)}
                    </p>
                )}
                {!value ? (
                    <p>{t('Loading…')}</p>
                ) : (
                    <form
                        className="space-y-4"
                        onSubmit={async (event) => {
                            event.preventDefault();
                            if (!confirmed || !dirty || busy) return;
                            setBusy(true);
                            setError('');
                            try {
                                await supportRequest<Settings>(url, {
                                    ...value,
                                    confirmed,
                                    request_id: requestId,
                                });
                                onClose();
                            } catch (error) {
                                setError(
                                    error instanceof SupportRequestError && error.status === 409
                                        ? 'These settings have changed. Close and reopen to load the latest settings.'
                                        : 'Unable to save. Refresh and try again.',
                                );
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {(Object.keys(fields) as (keyof typeof fields)[]).map((field) => (
                            <label key={field} className="flex items-center gap-3">
                                <input
                                    type="checkbox"
                                    checked={value[field]}
                                    disabled={busy}
                                    onChange={(event) => {
                                        setValue({ ...value, [field]: event.target.checked });
                                        setConfirmed(false);
                                        setRequestId(crypto.randomUUID());
                                    }}
                                />
                                {t(fields[field])}
                            </label>
                        ))}
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Restricted users will see “Please contact support” when confirming a new operation. Existing orders remain unchanged.',
                            )}
                        </p>
                        <label className="flex items-center gap-3">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                disabled={busy || !dirty}
                                onChange={(event) => setConfirmed(event.target.checked)}
                            />
                            {t('I confirm these changes.')}
                        </label>
                        <div className="flex justify-end gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={busy}
                                onClick={close}
                            >
                                {t('Cancel')}
                            </Button>
                            <Button type="submit" disabled={busy || !dirty || !confirmed}>
                                {t('Save')}
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
