import { useEffect } from 'react';
import { useForm } from '@inertiajs/react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { t, errorMessage } from '@/i18n/admin';

export function SupportProfile({
    name,
    url,
    onSaved,
    onState,
}: {
    name: string | null;
    url: string;
    onSaved?: () => void;
    onState?: (dirty: boolean, busy: boolean) => void;
}) {
    const form = useForm({ support_name: name ?? '' });
    useEffect(() => {
        onState?.(form.isDirty, form.processing);
    }, [form.isDirty, form.processing, onState]);
    return (
        <form
            className="flex items-center gap-3"
            onSubmit={(e) => {
                e.preventDefault();
                form.post(url, { preserveScroll: true, onSuccess: onSaved });
            }}
        >
            <Input
                aria-label={t('Support nickname')}
                placeholder={t('Support nickname')}
                maxLength={30}
                className="w-48"
                value={form.data.support_name}
                onChange={(e) => form.setData('support_name', e.target.value)}
            />
            <Button variant="secondary" disabled={form.processing}>
                {t('Save nickname')}
            </Button>
            {Object.values(form.errors).map((e, i) => (
                <span key={i} role="alert" className="text-sm text-destructive">
                    {errorMessage(e)}
                </span>
            ))}
        </form>
    );
}
