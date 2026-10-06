import { useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { t } from '@/i18n/admin';

export function CreateUserDialog({
    companies,
    company,
    onClose,
}: {
    companies: { id: string; name: string }[];
    company?: string;
    onClose: () => void;
}) {
    const form = useForm({
        company: company ?? '',
        email: '',
        display_name: '',
        password: '',
        password_confirmation: '',
        request_id: crypto.randomUUID(),
    });
    useEffect(() => {
        const guard = (event: BeforeUnloadEvent) => {
            if (form.isDirty) {
                event.preventDefault();
                event.returnValue = '';
            }
        };
        window.addEventListener('beforeunload', guard);
        return () => window.removeEventListener('beforeunload', guard);
    }, [form.isDirty]);
    const close = () => {
        if (!form.processing && (!form.isDirty || confirm(t('Discard unsaved changes?'))))
            onClose();
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent closeLabel={t('Close')} closeDisabled={form.processing}>
                <DialogHeader>
                    <DialogTitle>{t('Add account')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'New accounts are active and verified by default, with a zero-balance wallet.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-4"
                    onSubmit={(event) => {
                        event.preventDefault();
                        if (form.processing || !form.data.company) return;
                        form.post(`/platform/tenants/${form.data.company}/users`, {
                            preserveScroll: true,
                            onSuccess: onClose,
                        });
                    }}
                >
                    <label className="block space-y-1 text-sm">
                        <span>{t('Company')}</span>
                        <select
                            className="h-9 w-full rounded-md border bg-surface px-3"
                            required
                            value={form.data.company}
                            disabled={form.processing}
                            onChange={(e) => form.setData('company', e.target.value)}
                        >
                            <option value="">{t('Select company')}</option>
                            {companies.map((item) => (
                                <option key={item.id} value={item.id}>
                                    {item.name}
                                </option>
                            ))}
                        </select>
                    </label>
                    {(
                        [
                            ['email', 'Email', 'email', true, 255],
                            ['display_name', 'Display name', 'text', false, 100],
                            ['password', 'Password', 'password', true, 72],
                            ['password_confirmation', 'Confirm password', 'password', true, 72],
                        ] as const
                    ).map(([key, label, type, required, max]) => (
                        <label key={key} className="block space-y-1 text-sm">
                            <span>{t(label)}</span>
                            <input
                                className="h-9 w-full rounded-md border bg-surface px-3"
                                type={type}
                                required={required}
                                maxLength={max}
                                minLength={type === 'password' ? 6 : undefined}
                                autoComplete={
                                    type === 'password'
                                        ? 'new-password'
                                        : key === 'email'
                                          ? 'off'
                                          : 'nickname'
                                }
                                value={form.data[key]}
                                disabled={form.processing}
                                onChange={(e) => form.setData(key, e.target.value)}
                            />
                        </label>
                    ))}
                    <p className="text-sm text-muted-foreground">
                        {t('Use at least 6 characters for the password.')}
                    </p>
                    {Object.values(form.errors).length > 0 && (
                        <div role="alert" className="text-sm text-red-600">
                            {Object.values(form.errors).map((error, index) => (
                                <p key={index}>{t(error)}</p>
                            ))}
                        </div>
                    )}
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={close}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button type="submit" disabled={form.processing || !form.data.company}>
                            {t(form.processing ? 'Creating…' : 'Create account')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
