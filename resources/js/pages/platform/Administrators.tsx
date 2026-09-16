import { Head, useForm, usePage } from '@inertiajs/react';
import { t, useAdminTranslation, dateTime, errorMessage } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { FormField } from '@/components/ui/form-field';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
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
import type { SharedProps } from '@/types/global';

type Team = {
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
        role: 'PLATFORM_AUDITOR',
    });
    const fields = [
        ['name', 'Name', 'text'],
        ['email', 'Login account (email)', 'email'],
        ['password', 'Administrator password', 'password'],
        ['password_confirmation', 'Confirm password', 'password'],
    ] as const;
    return (
        <PlatformLayout>
            <Head title={t('SaaS administrators')} />
            <div className="space-y-6">
                <PageHeader title={t('SaaS administrators')} eyebrow={t('Access control')} />
                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Add administrator')}</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="grid max-w-3xl gap-5 sm:grid-cols-2"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post('/platform/administrators', {
                                        onSuccess: () => form.reset('name', 'email'),
                                        onFinish: () =>
                                            form.reset('password', 'password_confirmation'),
                                    });
                                }}
                            >
                                <p className="text-sm text-muted-foreground sm:col-span-2">
                                    {t(
                                        'This grants access to the SaaS backend. Confirm the account and role before creating. Use 12–72 characters including uppercase and lowercase letters and numbers for the new password.',
                                    )}
                                </p>
                                {(form.errors as Record<string, string>).form && (
                                    <p role="alert" className="text-destructive sm:col-span-2">
                                        {errorMessage((form.errors as Record<string, string>).form)}
                                    </p>
                                )}
                                {fields.map(([key, label, type]) => (
                                    <FormField
                                        key={key}
                                        id={`platform-admin-${key}`}
                                        label={t(label)}
                                        error={errorMessage(form.errors[key])}
                                    >
                                        <Input
                                            id={`platform-admin-${key}`}
                                            type={type}
                                            value={form.data[key]}
                                            onChange={(event) =>
                                                form.setData(key, event.target.value)
                                            }
                                            required
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
                                <FormField
                                    id="platform-admin-role"
                                    label={t('Role')}
                                    error={errorMessage(form.errors.role)}
                                >
                                    <Select
                                        value={form.data.role}
                                        onValueChange={(value) => form.setData('role', value)}
                                    >
                                        <SelectTrigger id="platform-admin-role">
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
                                <p className="text-sm text-muted-foreground sm:col-span-2">
                                    {t(
                                        'Platform administrators can manage companies and confirm top-ups. Platform auditors have read-only access.',
                                    )}
                                </p>
                                <Button className="justify-self-start" disabled={form.processing}>
                                    {t('Confirm and create administrator')}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
                <div className="overflow-x-auto rounded-xl border bg-surface">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                {[
                                    'Name',
                                    'Login account (email)',
                                    'Role',
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
                                    <TableCell>{t(member.role)}</TableCell>
                                    <TableCell>{t(member.status)}</TableCell>
                                    <TableCell>{t(member.accountStatus)}</TableCell>
                                    <TableCell>{dateTime(member.createdAt)}</TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </PlatformLayout>
    );
}
