import { useState } from 'react';
import { router } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { errorMessage, t } from '@/i18n';

export function FinancialConfirmation({
    title,
    warning,
    url,
    payload,
    disabled = false,
    onCompleted,
}: {
    title: string;
    warning: string | string[];
    url: string;
    payload: Record<string, string>;
    disabled?: boolean;
    onCompleted?: () => void;
}) {
    const [open, setOpen] = useState(false);
    const [password, setPassword] = useState('');
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState<string>();
    const changeOpen = (value: boolean) => {
        if (busy) return;
        setOpen(value);
        setPassword('');
        setConfirmed(false);
        setError(undefined);
    };
    return (
        <>
            <Button disabled={disabled} onClick={() => changeOpen(true)}>
                {title}
            </Button>
            <Dialog open={open} onOpenChange={changeOpen}>
                <DialogContent className="max-h-[90dvh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{title}</DialogTitle>
                        {Array.isArray(warning) ? (
                            <DialogDescription asChild>
                                <div>
                                    <ul className="list-disc space-y-2 pl-5 text-left leading-6">
                                        {warning.map((message) => (
                                            <li key={message}>{message}</li>
                                        ))}
                                    </ul>
                                </div>
                            </DialogDescription>
                        ) : (
                            <DialogDescription>{warning}</DialogDescription>
                        )}
                    </DialogHeader>
                    <form
                        className="space-y-4"
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (busy || !confirmed || password === '') return;
                            setBusy(true);
                            setError(undefined);
                            router.post(
                                url,
                                { ...payload, current_password: password, confirmed },
                                {
                                    preserveScroll: true,
                                    onSuccess: () => {
                                        setOpen(false);
                                        setConfirmed(false);
                                        onCompleted?.();
                                    },
                                    onError: (errors) =>
                                        setError(errorMessage(Object.values(errors)[0])),
                                    onFinish: () => {
                                        setBusy(false);
                                        setPassword('');
                                    },
                                },
                            );
                        }}
                    >
                        <label className="block space-y-2">
                            <span>{t('Current password')}</span>
                            <Input
                                type="password"
                                autoComplete="current-password"
                                value={password}
                                onChange={(e) => setPassword(e.target.value)}
                                required
                            />
                        </label>
                        <label className="flex items-start gap-3 text-sm">
                            <Checkbox
                                checked={confirmed}
                                onCheckedChange={(value) => setConfirmed(value === true)}
                            />
                            <span>{t('I understand and confirm this action.')}</span>
                        </label>
                        {error && (
                            <p role="alert" className="text-sm text-red-700">
                                {error}
                            </p>
                        )}
                        <Button type="submit" disabled={busy || !confirmed || password === ''}>
                            {t('Confirm')}
                        </Button>
                    </form>
                </DialogContent>
            </Dialog>
        </>
    );
}
