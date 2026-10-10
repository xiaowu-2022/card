import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Button } from '@/components/ui/button';
import { supportRequest } from '@/components/support/supportRequest';
import { t } from '@/i18n/admin';
export function CustomerRemarkDialog({
    user,
    onClose,
    onSaved,
}: {
    user: { id: string; companyId: string; remark: string | null; remarkRevision: number };
    onClose: () => void;
    onSaved?: () => void;
}) {
    const [value, setValue] = useState(user.remark ?? '');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState(false);
    const close = () => {
        if (!busy && (value === (user.remark ?? '') || confirm(t('Discard unsaved changes?'))))
            onClose();
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
                    <DialogTitle>{t('Customer remark')}</DialogTitle>
                </DialogHeader>
                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        void (async () => {
                            setBusy(true);
                            setError(false);
                            try {
                                await supportRequest(
                                    `/platform/tenants/${user.companyId}/users/${user.id}/support-remark`,
                                    { remark: value, revision: user.remarkRevision },
                                );
                                onClose();
                                if (onSaved) onSaved();
                                else router.reload({ only: ['users'] });
                            } catch {
                                setError(true);
                            } finally {
                                setBusy(false);
                            }
                        })();
                    }}
                    className="space-y-4"
                >
                    <label className="block">
                        {t('Customer remark')}
                        <input
                            className="mt-2 w-full rounded border p-2"
                            value={value}
                            maxLength={60}
                            disabled={busy}
                            onChange={(e) => setValue(e.target.value)}
                        />
                    </label>
                    <p className="text-sm text-muted-foreground">
                        {t('Shared within the company. Clear to show the original name.')}
                    </p>
                    {error && (
                        <OperationFeedback role="alert">
                            {t('Unable to save. Refresh and try again.')}
                        </OperationFeedback>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button type="button" variant="secondary" disabled={busy} onClick={close}>
                            {t('Cancel')}
                        </Button>
                        <Button disabled={busy} type="submit">
                            {t('Save')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
