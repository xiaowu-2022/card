import { showOperationResult } from '@/components/admin/operation-result';
import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { useState } from 'react';
import { usePage } from '@inertiajs/react';
import type { SharedProps } from '@/types/global';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { t } from '@/i18n/admin';

export function WithdrawalExport({
    filters,
    total,
}: {
    filters: Record<string, string | undefined>;
    total: number;
}) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    if (!permissions.includes('withdrawals.read') || !permissions.includes('withdrawals.review'))
        return null;

    function changeOpen(value: boolean) {
        if (busy) return;
        setOpen(value);
        setPassword('');
        setConfirmed(false);
        setError('');
    }

    async function download() {
        setBusy(true);
        setError('');
        try {
            const csrf = decodeURIComponent(
                document.cookie
                    .split('; ')
                    .find((c) => c.startsWith('XSRF-TOKEN='))
                    ?.slice(11) ?? '',
            );
            const selected = Object.fromEntries(
                Object.entries(filters).filter(([key, value]) => key !== 'page' && value),
            );
            const response = await fetch('/platform/asset-withdrawals/export', {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': csrf,
                },
                body: JSON.stringify({ ...selected, password, confirmed }),
            });
            if (!response.ok || !response.headers.get('Content-Type')?.startsWith('text/csv')) {
                if (response.status === 403)
                    throw new Error('Check your password and withdrawal permissions.');
                if (response.status === 422) {
                    const body = (await response.json()) as { errors?: { export?: string[] } };
                    if (body.errors?.export?.[0]) throw new Error(body.errors.export[0]);
                }
                throw new Error('Unable to export. Refresh the list and try again.');
            }
            const url = URL.createObjectURL(await response.blob());
            const link = document.createElement('a');
            link.href = url;
            link.download =
                response.headers.get('Content-Disposition')?.match(/filename="([^"]+)"/)?.[1] ??
                'withdrawals.csv';
            document.body.appendChild(link);
            link.click();
            link.remove();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
            showOperationResult('success', 'Export prepared.');
            setOpen(false);
            setConfirmed(false);
        } catch (e) {
            setError(
                e instanceof Error
                    ? e.message
                    : 'Unable to export. Refresh the list and try again.',
            );
        } finally {
            setPassword('');
            setBusy(false);
        }
    }

    return (
        <>
            <Button variant="secondary" disabled={total === 0} onClick={() => changeOpen(true)}>
                {t('Export withdrawals')}
            </Button>
            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent closeDisabled={busy} closeLabel={t('Close')}>
                    <DialogHeader>
                        <DialogTitle>{t('Export withdrawals')}</DialogTitle>
                        <DialogDescription>
                            {t('Export all {{count}} filtered records as CSV.', { count: total })}
                        </DialogDescription>
                    </DialogHeader>
                    <form
                        className="mt-4 space-y-4"
                        onSubmit={(e) => {
                            e.preventDefault();
                            void download();
                        }}
                    >
                        <p className="text-sm text-muted-foreground">
                            {t(
                                'Includes full payout addresses, lifetime successful totals in each order currency, current agent rank and partner status. Rank 0 means no active agent level.',
                            )}
                        </p>
                        <label className="block space-y-2 text-sm">
                            <span>{t('Current password')}</span>
                            <Input
                                type="password"
                                autoComplete="current-password"
                                required
                                disabled={busy}
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                            />
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={confirmed}
                                disabled={busy}
                                onChange={(e) => setConfirmed(e.target.checked)}
                            />
                            {t('I confirm exporting these payout details.')}
                        </label>
                        {total > 10000 && (
                            <p role="alert" className="text-sm text-destructive">
                                {t(
                                    'Too many withdrawals. Narrow the filters to 10,000 records or fewer.',
                                )}
                            </p>
                        )}
                        {error && (
                            <OperationFeedback role="alert" className="text-sm text-destructive">
                                {t(error)}
                            </OperationFeedback>
                        )}
                        <Button
                            type="submit"
                            disabled={busy || !confirmed || !password || total > 10000}
                        >
                            {t(busy ? 'Exporting…' : 'Download CSV')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
