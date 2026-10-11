import { OperationFeedback } from '@/components/admin/OperationFeedback';
import { AdminPageLinks, type ListPagination } from '@/components/shared/AdminPageLinks';
import { useState } from 'react';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Head, useForm, usePage } from '@inertiajs/react';
import { t, useAdminTranslation, dateTime, errorMessage } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type { SharedProps } from '@/types/global';

type Team = {
    pagination?: ListPagination;
    roles: string[];
    members: {
        id: string;
        name: string;
        email: string;
        role: string;
        status: string;
        accountStatus: string;
        createdAt: string;
    }[];
};

export default function Administrators({ team }: { team: Team }) {
    useAdminTranslation();
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('admin_team.manage');
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: 'PLATFORM_ADMIN',
    });
    const [open, setOpen] = useState(false);
    const fields = [
        ['name', 'Name', 'text'],
        ['email', 'Login account', 'text'],
        ['password', 'Administrator password', 'password'],
        ['password_confirmation', 'Confirm password', 'password'],
    ] as const;
    return (
        <PlatformLayout
            title={t('SaaS administrators')}
            actions={
                canManage && <Button onClick={() => setOpen(true)}>{t('Add administrator')}</Button>
            }
        >
            <Head title={t('SaaS administrators')} />
            <div className="space-y-4">
                {canManage && (
                    <Dialog
                        open={open}
                        onOpenChange={(next) => {
                            if (
                                !form.processing &&
                                (next || !form.isDirty || confirm(t('Discard unsaved changes?')))
                            )
                                setOpen(next);
                        }}
                    >
                        <DialogContent
                            presentation="drawer"
                            className="max-w-3xl"
                            closeDisabled={form.processing}
                            aria-describedby={undefined}
                        >
                            <DialogTitle>{t('Add administrator')}</DialogTitle>
                            <div className="mt-4">
                                <div>
                                    <form
                                        className="grid max-w-3xl gap-5 sm:grid-cols-2"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            form.post('/platform/administrators', {
                                                onSuccess: () => {
                                                    form.reset();
                                                    setOpen(false);
                                                },
                                                onFinish: () =>
                                                    form.reset('password', 'password_confirmation'),
                                            });
                                        }}
                                    >
                                        <p className="text-sm text-muted-foreground sm:col-span-2">
                                            {t(
                                                'This grants full access to the SaaS backend. Confirm the account before creating. Use 6–72 characters for the new password.',
                                            )}
                                        </p>
                                        {(form.errors as Record<string, string>).form && (
                                            <OperationFeedback
                                                role="alert"
                                                className="text-destructive sm:col-span-2"
                                            >
                                                {errorMessage(
                                                    (form.errors as Record<string, string>).form,
                                                )}
                                            </OperationFeedback>
                                        )}
                                        {fields.map(([key, label, type]) => (
                                            <FormField
                                                key={key}
                                                id={`platform-admin-${key}`}
                                                label={t(label)}
                                                description={
                                                    key === 'email'
                                                        ? t(
                                                              'Choose a unique login account; an email address is not required. Accounts are case-insensitive and cannot contain spaces.',
                                                          )
                                                        : undefined
                                                }
                                                error={errorMessage(form.errors[key])}
                                            >
                                                <Input
                                                    id={`platform-admin-${key}`}
                                                    type={type}
                                                    autoCapitalize="none"
                                                    spellCheck={false}
                                                    value={form.data[key]}
                                                    onChange={(event) =>
                                                        form.setData(key, event.target.value)
                                                    }
                                                    required
                                                    minLength={type === 'password' ? 6 : undefined}
                                                    maxLength={
                                                        type === 'password'
                                                            ? 72
                                                            : key === 'name'
                                                              ? 120
                                                              : 255
                                                    }
                                                    autoComplete={
                                                        type === 'password' ? 'new-password' : 'off'
                                                    }
                                                />
                                            </FormField>
                                        ))}
                                        <Button
                                            className="justify-self-start"
                                            disabled={form.processing}
                                        >
                                            {t('Confirm and create administrator')}
                                        </Button>
                                    </form>
                                </div>
                            </div>
                        </DialogContent>
                    </Dialog>
                )}
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {[
                                    'Name',
                                    'Login account',
                                    'Membership status',
                                    'Account status',
                                    'Created',
                                ].map((label) => (
                                    <TableHead key={label}>{t(label)}</TableHead>
                                ))}
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {team.members.map((member) => (
                                <TableRow key={member.id}>
                                    <TableCell>{member.name}</TableCell>
                                    <TableCell>{member.email}</TableCell>
                                    <TableCell>{t(member.status)}</TableCell>
                                    <TableCell>{t(member.accountStatus)}</TableCell>
                                    <TableCell>{dateTime(member.createdAt)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                    <AdminPageLinks page={team.pagination} />
                </div>
            </div>
        </PlatformLayout>
    );
}
