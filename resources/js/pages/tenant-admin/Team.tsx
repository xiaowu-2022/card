import { useState } from 'react';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
    DialogDescription,
} from '@/components/ui/dialog';
import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { ConfigurationForm } from '@/components/admin/CompanyConfiguration';
import { useAdminTranslation, t, errorMessage } from '@/i18n/admin';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { CompanyConfigurationHeader as PageHeader } from '@/components/admin/CompanyConfiguration';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { FormField } from '@/components/ui/form-field';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { CompanyConfigurationLayout as TenantAdminLayout } from '@/components/admin/CompanyConfiguration';
import type { SharedProps } from '@/types/global';

type TeamData = {
    members: {
        id: string;
        name: string;
        email: string;
        role: string;
        status: string;
        accountStatus: string;
    }[];
    invitations: { id: string; email: string; role: string; status: string; expiresAt: string }[];
    roles: string[];
};
export default function Team({ team }: { team: TeamData }) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const [createOpen, setCreateOpen] = useState(false);
    const [editing, setEditing] = useState<TeamData['members'][number] | null>(null);
    const form = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        current_password: '',
        role: team.roles[0] ?? 'TENANT_ADMIN',
    });
    const configurationProps = usePage<
        SharedProps & { configurationBase?: string; configurationReadOnly?: boolean }
    >().props;
    const canManage =
        Boolean(configurationProps.configurationBase) &&
        configurationProps.auth.admin?.permissions.includes('tenant.manage');
    const closeCreate = () => {
        if (form.processing) return;
        setCreateOpen(false);
        form.reset();
        form.clearErrors();
    };
    const formError = (form.errors as Record<string, string>).form;
    return (
        <TenantAdminLayout>
            <Head title={t('Admin team')} />
            <div className="space-y-6">
                <PageHeader eyebrow={t('Access control')} title={t('Admin team')} />
                {canManage && (
                    <div className="flex justify-end">
                        <Button
                            onClick={() => {
                                form.reset();
                                form.clearErrors();
                                setCreateOpen(true);
                            }}
                        >
                            {t('Add administrator')}
                        </Button>
                    </div>
                )}
                <Dialog
                    open={createOpen}
                    onOpenChange={(open) => {
                        if (!open) closeCreate();
                    }}
                >
                    <DialogContent
                        className="max-w-2xl max-h-[85dvh] overflow-y-auto"
                        closeLabel={t('Close')}
                        closeDisabled={form.processing}
                        aria-describedby={undefined}
                    >
                        <DialogHeader>
                            <DialogTitle>{t('Add administrator')}</DialogTitle>
                        </DialogHeader>
                        <ConfigurationForm
                            className="grid max-w-3xl gap-5 sm:grid-cols-2"
                            onSubmit={(event) => {
                                event.preventDefault();
                                form.post(configurationUrl('/admin/team/administrators'), {
                                    onSuccess: () => {
                                        form.reset();
                                        setCreateOpen(false);
                                    },
                                    preserveScroll: true,
                                    onFinish: () =>
                                        form.reset(
                                            'password',
                                            'password_confirmation',
                                            'current_password',
                                        ),
                                });
                            }}
                        >
                            {formError && (
                                <p
                                    role="alert"
                                    className="text-sm font-medium text-danger sm:col-span-2"
                                >
                                    {errorMessage(formError)}
                                </p>
                            )}
                            <FormField
                                id="admin-name"
                                label={t('Name')}
                                error={errorMessage(form.errors.name)}
                            >
                                <Input
                                    id="admin-name"
                                    autoComplete="off"
                                    value={form.data.name}
                                    onChange={(event) => form.setData('name', event.target.value)}
                                    required
                                    maxLength={120}
                                />
                            </FormField>
                            <FormField
                                id="invite-email"
                                label={t('Login account (email)')}
                                error={errorMessage(form.errors.email)}
                            >
                                <Input
                                    id="invite-email"
                                    type="email"
                                    autoComplete="off"
                                    required
                                    value={form.data.email}
                                    onChange={(event) => form.setData('email', event.target.value)}
                                />
                            </FormField>
                            <FormField
                                id="admin-password"
                                label={t('Administrator password')}
                                description={t(
                                    'Use 12–72 characters including uppercase and lowercase letters and numbers.',
                                )}
                                error={errorMessage(form.errors.password)}
                            >
                                <Input
                                    id="admin-password"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    minLength={12}
                                    maxLength={72}
                                    value={form.data.password}
                                    onChange={(event) =>
                                        form.setData('password', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="admin-password-confirmation"
                                label={t('Confirm password')}
                                error={errorMessage(form.errors.password_confirmation)}
                            >
                                <Input
                                    id="admin-password-confirmation"
                                    type="password"
                                    autoComplete="new-password"
                                    required
                                    minLength={12}
                                    maxLength={72}
                                    value={form.data.password_confirmation}
                                    onChange={(event) =>
                                        form.setData('password_confirmation', event.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="invite-role"
                                label={t('Role')}
                                error={errorMessage(form.errors.role)}
                            >
                                <Select
                                    value={form.data.role}
                                    onValueChange={(value) => form.setData('role', value)}
                                >
                                    <SelectTrigger id="invite-role">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {team.roles.map((role) => (
                                            <SelectItem key={role} value={role}>
                                                {t(role)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                            <FormField
                                id="admin-current-password"
                                label={t('Your current password')}
                                error={errorMessage(form.errors.current_password)}
                            >
                                <Input
                                    id="admin-current-password"
                                    type="password"
                                    autoComplete="current-password"
                                    required
                                    value={form.data.current_password}
                                    onChange={(event) =>
                                        form.setData('current_password', event.target.value)
                                    }
                                />
                            </FormField>
                            <p className="text-sm text-muted-foreground sm:col-span-2">
                                {t(
                                    'Creating this administrator grants immediate access with the selected role. Confirm with your own password. Existing accounts and passwords will not be changed.',
                                )}
                            </p>
                            <div className="flex justify-end gap-2 sm:col-span-2">
                                <Button
                                    type="button"
                                    variant="secondary"
                                    disabled={form.processing}
                                    onClick={() => closeCreate()}
                                >
                                    {t('Cancel')}
                                </Button>
                                <Button disabled={form.processing}>
                                    {t('Confirm and create administrator')}
                                </Button>
                            </div>
                        </ConfigurationForm>
                    </DialogContent>
                </Dialog>
                {editing && (
                    <EditMemberDialog
                        key={editing.id}
                        member={editing}
                        roles={team.roles}
                        onClose={() => setEditing(null)}
                    />
                )}
                <div
                    className={
                        team.invitations.length > 0 ? 'grid gap-6 xl:grid-cols-2' : 'grid gap-6'
                    }
                >
                    <Card className="min-w-0 overflow-hidden">
                        <CardContent className="p-0">
                            <Table className="min-w-[760px]">
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>{t('Name')}</TableHead>
                                        <TableHead>{t('Login account (email)')}</TableHead>
                                        <TableHead>{t('Role')}</TableHead>
                                        <TableHead>{t('Membership status')}</TableHead>
                                        <TableHead>{t('Account status')}</TableHead>
                                        {canManage && (
                                            <TableHead className="text-right">
                                                {t('Actions')}
                                            </TableHead>
                                        )}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {team.members.map((member) => (
                                        <TableRow key={member.id}>
                                            <TableCell>
                                                <p className="font-medium">{member.name}</p>
                                            </TableCell>
                                            <TableCell>{member.email}</TableCell>
                                            <TableCell>{t(member.role)}</TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        member.status === 'ACTIVE'
                                                            ? 'SUCCESS'
                                                            : 'WARNING'
                                                    }
                                                    label={t(member.status)}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        member.accountStatus === 'ACTIVE'
                                                            ? 'SUCCESS'
                                                            : 'WARNING'
                                                    }
                                                    label={t(member.accountStatus)}
                                                />
                                            </TableCell>
                                            {canManage && (
                                                <TableCell className="text-right whitespace-nowrap">
                                                    {member.role === 'TENANT_OWNER' ? (
                                                        <span className="text-xs text-muted-foreground">
                                                            {t('Owner protected')}
                                                        </span>
                                                    ) : member.status === 'REVOKED' ? (
                                                        <span className="text-muted-foreground">
                                                            —
                                                        </span>
                                                    ) : (
                                                        <Button
                                                            size="sm"
                                                            variant="secondary"
                                                            onClick={() => setEditing(member)}
                                                        >
                                                            {t('Edit')}
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            )}
                                        </TableRow>
                                    ))}
                                    {team.members.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={canManage ? 6 : 5}
                                                className="py-10 text-center text-muted-foreground"
                                            >
                                                {t('No administrators yet.')}
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    {team.invitations.length > 0 && (
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Invitations')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {team.invitations.length === 0 ? (
                                    <p className="text-sm text-muted-foreground">
                                        {t('No invitations.')}
                                    </p>
                                ) : (
                                    team.invitations.map((invite) => (
                                        <div
                                            key={invite.id}
                                            className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                        >
                                            <div>
                                                <p className="font-medium">{invite.email}</p>
                                                <p className="text-sm text-muted-foreground">
                                                    {t(invite.role)} · {t(invite.status)}
                                                </p>
                                            </div>
                                            {canManage && invite.status === 'PENDING' && (
                                                <div className="flex gap-2">
                                                    <Button
                                                        size="sm"
                                                        variant="secondary"
                                                        data-config-write
                                                        onClick={() =>
                                                            router.post(
                                                                configurationUrl(
                                                                    `/admin/team/invitations/${invite.id}/resend`,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {t('Resend')}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        data-config-write
                                                        onClick={() =>
                                                            router.post(
                                                                configurationUrl(
                                                                    `/admin/team/invitations/${invite.id}/cancel`,
                                                                ),
                                                            )
                                                        }
                                                    >
                                                        {t('Cancel')}
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    ))
                                )}
                            </CardContent>
                        </Card>
                    )}
                </div>
            </div>
        </TenantAdminLayout>
    );
}

function EditMemberDialog({
    member,
    roles,
    onClose,
}: {
    member: TeamData['members'][number];
    roles: string[];
    onClose: () => void;
}) {
    const configurationUrl = useCompanyConfigurationUrl();
    const form = useForm({ role: member.role, status: member.status, current_password: '' });
    const close = () => {
        if (!form.processing) {
            form.reset();
            form.clearErrors();
            onClose();
        }
    };
    return (
        <Dialog
            open
            onOpenChange={(open) => {
                if (!open) close();
            }}
        >
            <DialogContent
                className="max-w-lg max-h-[85dvh] overflow-y-auto"
                closeLabel={t('Close')}
                closeDisabled={form.processing}
            >
                <DialogHeader>
                    <DialogTitle>{t('Edit administrator')}</DialogTitle>
                    <DialogDescription>
                        {member.name} · {member.email}
                    </DialogDescription>
                </DialogHeader>
                <ConfigurationForm
                    className="space-y-5"
                    onSubmit={(event) => {
                        event.preventDefault();
                        form.put(configurationUrl(`/admin/team/memberships/${member.id}`), {
                            preserveScroll: true,
                            onSuccess: onClose,
                            onFinish: () => form.reset('current_password'),
                        });
                    }}
                >
                    {(form.errors as Record<string, string>).form && (
                        <p role="alert" className="text-sm text-danger">
                            {errorMessage((form.errors as Record<string, string>).form)}
                        </p>
                    )}
                    <FormField
                        id="edit-admin-role"
                        label={t('Role')}
                        error={errorMessage(form.errors.role)}
                    >
                        <Select
                            value={form.data.role}
                            onValueChange={(value) => form.setData('role', value)}
                        >
                            <SelectTrigger id="edit-admin-role">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {roles.map((role) => (
                                    <SelectItem key={role} value={role}>
                                        {t(role)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        id="edit-admin-status"
                        label={t('Membership status')}
                        error={errorMessage(form.errors.status)}
                    >
                        <Select
                            value={form.data.status}
                            onValueChange={(value) => form.setData('status', value)}
                        >
                            <SelectTrigger id="edit-admin-status">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {['ACTIVE', 'SUSPENDED'].map((status) => (
                                    <SelectItem key={status} value={status}>
                                        {t(status)}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </FormField>
                    <FormField
                        id="edit-current-password"
                        label={t('Your current password')}
                        error={errorMessage(form.errors.current_password)}
                    >
                        <Input
                            id="edit-current-password"
                            type="password"
                            autoComplete="current-password"
                            required
                            value={form.data.current_password}
                            onChange={(event) =>
                                form.setData('current_password', event.target.value)
                            }
                        />
                    </FormField>
                    <p className="text-sm text-muted-foreground">
                        {t(
                            'Changes take effect for this company immediately. Suspending membership blocks access to this company. Confirm with your current password.',
                        )}
                    </p>
                    <div className="flex justify-end gap-2">
                        <Button
                            type="button"
                            variant="secondary"
                            disabled={form.processing}
                            onClick={close}
                        >
                            {t('Cancel')}
                        </Button>
                        <Button disabled={form.processing}>{t('Confirm and save')}</Button>
                    </div>
                </ConfigurationForm>
            </DialogContent>
        </Dialog>
    );
}
