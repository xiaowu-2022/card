import { useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
import { t, errorMessage, useAdminTranslation } from '@/i18n/admin';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import type { SharedProps } from '@/types/global';

export function RenameCompany({
    company,
}: {
    company: { id: string; name: string; slug: string };
}) {
    useAdminTranslation();
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('tenant.manage');
    const [open, setOpen] = useState(false);
    const form = useForm({ name: company.name });
    if (!canManage) return null;

    return (
        <Dialog
            open={open}
            onOpenChange={(value) => {
                if (form.processing) return;
                if (value) {
                    form.setData('name', company.name);
                    form.clearErrors();
                }
                setOpen(value);
            }}
        >
            <DialogTrigger asChild>
                <Button variant="secondary" size="sm">
                    {t('Edit company name')}
                </Button>
            </DialogTrigger>
            <DialogContent closeLabel={t('Close')}>
                <DialogHeader>
                    <DialogTitle>{t('Edit company name')}</DialogTitle>
                    <DialogDescription>
                        {t(
                            'The company identifier, domains and frontend brand name remain unchanged.',
                        )}
                    </DialogDescription>
                </DialogHeader>
                <form
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(`/platform/tenants/${company.id}/name`, {
                            preserveScroll: true,
                            onSuccess: () => setOpen(false),
                        });
                    }}
                >
                    <FormField id="company-slug" label={t('Company identifier')}>
                        <Input
                            id="company-slug"
                            value={company.slug}
                            readOnly
                            className="bg-muted"
                        />
                    </FormField>
                    <FormField
                        id="company-name"
                        label={t('Company name')}
                        error={errorMessage(form.errors.name)}
                    >
                        <Input
                            id="company-name"
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                            maxLength={120}
                            required
                            disabled={form.processing}
                        />
                    </FormField>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={() => setOpen(false)}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button disabled={form.processing || !form.data.name.trim()}>
                            {t('Save')}
                        </Button>
                    </div>
                </form>
            </DialogContent>
        </Dialog>
    );
}
