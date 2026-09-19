import { useForm } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { t, errorMessage } from '@/i18n/admin';
export function InvitationPosterSettings({
    tenant,
    background,
}: {
    tenant: string;
    background: string | null;
}) {
    const form = useForm<{ background: File | null }>({ background: null });
    return (
        <form
            className="space-y-3 rounded-xl border bg-surface p-4"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(`/platform/tenants/${tenant}/configuration/invitation-poster`, {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => form.reset(),
                });
            }}
        >
            <h2 className="font-semibold">{t('Invitation poster')}</h2>
            <p className="text-sm text-muted-foreground">
                {t(
                    'Upload a JPG, PNG or WebP background (up to 8 MB). The invitation QR code is placed inside the bottom of the image.',
                )}
            </p>
            <div className="flex flex-wrap items-center gap-4">
                {background && (
                    <img
                        src={background}
                        alt={t('Poster background')}
                        className="h-28 w-20 rounded border object-contain"
                    />
                )}
                <Input
                    aria-label={t('Poster background')}
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="max-w-sm"
                    onChange={(event) =>
                        form.setData('background', event.target.files?.[0] ?? null)
                    }
                />
                <Button disabled={!form.data.background || form.processing}>{t('Save')}</Button>
            </div>
            {Object.values(form.errors).map((error, i) => (
                <p role="alert" className="text-sm text-destructive" key={i}>
                    {errorMessage(error)}
                </p>
            ))}
        </form>
    );
}
