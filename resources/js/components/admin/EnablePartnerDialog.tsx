import { useState } from 'react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { OperationFeedback } from './OperationFeedback';
import { supportRequest } from '@/components/support/supportRequest';
import { t } from '@/i18n/admin';
import type { PlatformUser } from './UserManagement';

export function EnablePartnerDialog({
    user,
    onClose,
    onSaved,
}: {
    user: PlatformUser;
    onClose: () => void;
    onSaved: () => void;
}) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open && !busy) onClose();
            }}
        >
            <DialogContent closeDisabled={busy} closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>{t('Set as partner')}</DialogTitle>
                </DialogHeader>
                <p className="break-all text-sm">
                    {user.companyName} · {user.displayName || user.email || user.accountId} ·{' '}
                    {user.accountId}
                </p>
                <p className="text-sm">
                    {t(
                        'Confirm setting this account as a partner? New partners use the default 40% share; existing disabled partners keep their share. Card funding will be blocked by default.',
                    )}
                </p>
                {error && <OperationFeedback role="alert">{t(error)}</OperationFeedback>}
                <div className="flex justify-end gap-2">
                    <Button variant="secondary" disabled={busy} onClick={onClose}>
                        {t('Cancel')}
                    </Button>
                    <Button
                        disabled={busy}
                        onClick={async () => {
                            setBusy(true);
                            setError('');
                            try {
                                await supportRequest(
                                    `/platform/tenants/${user.companyId}/users/${user.id}/partner`,
                                    { confirmed: true },
                                );
                                onSaved();
                            } catch {
                                setError('Unable to save. Refresh and try again.');
                            } finally {
                                setBusy(false);
                            }
                        }}
                    >
                        {t(busy ? 'Saving…' : 'Confirm')}
                    </Button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
