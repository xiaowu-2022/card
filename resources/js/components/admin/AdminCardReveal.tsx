import { useEffect, useRef, useState } from 'react';
import { t } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';

export function AdminCardReveal({ card }: { card: { id: string; tenantId: string } }) {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [details, setDetails] = useState<{ pan: string; expiry: string; cvv: string } | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef<AbortController | null>(null);
    function clear() {
        pending.current?.abort();
        pending.current = null;
        setPassword('');
        setDetails(null);
        setBusy(false);
        setOpen(false);
        setError('');
    }
    useEffect(() => {
        const hide = () => {
            if (document.hidden) clear();
        };
        document.addEventListener('visibilitychange', hide);
        return () => {
            pending.current?.abort();
            document.removeEventListener('visibilitychange', hide);
        };
    }, []);
    useEffect(() => {
        if (!details) return;
        const timer = setTimeout(clear, 30000);
        return () => clearTimeout(timer);
    }, [details]);
    return (
        <>
            <Button variant="secondary" size="sm" onClick={() => setOpen(true)}>
                {t('View card information')}
            </Button>
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!value) clear();
                }}
            >
                <DialogContent aria-describedby={undefined}>
                    <DialogHeader>
                        <DialogTitle>{t('View card information')}</DialogTitle>
                    </DialogHeader>
                    {details ? (
                        <dl className="space-y-3">
                            <div>
                                <dt>{t('Card number')}</dt>
                                <dd className="font-mono">{details.pan}</dd>
                            </div>
                            <div>
                                <dt>{t('Expiry')}</dt>
                                <dd>{details.expiry}</dd>
                            </div>
                            <div>
                                <dt>CVV</dt>
                                <dd>{details.cvv}</dd>
                            </div>
                        </dl>
                    ) : (
                        <form
                            className="space-y-3"
                            onSubmit={(event) => {
                                event.preventDefault();
                                void (async () => {
                                    setBusy(true);
                                    setError('');
                                    const controller = new AbortController();
                                    pending.current = controller;
                                    const token = document.cookie
                                        .split('; ')
                                        .find((item) => item.startsWith('XSRF-TOKEN='))
                                        ?.split('=')
                                        .slice(1)
                                        .join('=');
                                    const body = JSON.stringify({ password });
                                    setPassword('');
                                    try {
                                        const response = await fetch(
                                            `/platform/tenants/${card.tenantId}/cards/${card.id}/reveal`,
                                            {
                                                method: 'POST',
                                                credentials: 'same-origin',
                                                cache: 'no-store',
                                                signal: AbortSignal.any([
                                                    controller.signal,
                                                    AbortSignal.timeout(15000),
                                                ]),
                                                headers: {
                                                    Accept: 'application/json',
                                                    'Content-Type': 'application/json',
                                                    'X-XSRF-TOKEN': decodeURIComponent(token ?? ''),
                                                },
                                                body,
                                            },
                                        );
                                        if (!response.ok) throw new Error();
                                        const value = (await response.json()) as {
                                            pan: string;
                                            expiry: string;
                                            cvv: string;
                                        };
                                        if (!controller.signal.aborted) setDetails(value);
                                    } catch {
                                        if (!controller.signal.aborted)
                                            setError(
                                                t(
                                                    'Card details could not be loaded. Please try again later.',
                                                ),
                                            );
                                    } finally {
                                        if (!controller.signal.aborted) setBusy(false);
                                    }
                                })();
                            }}
                        >
                            <label className="block space-y-2">
                                {t('Current password')}
                                <Input
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={password}
                                    onChange={(e) => setPassword(e.target.value)}
                                />
                            </label>
                            <Button disabled={busy}>{t('View card information')}</Button>
                        </form>
                    )}
                    {error && (
                        <p role="alert" className="text-sm text-destructive">
                            {error}
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}
