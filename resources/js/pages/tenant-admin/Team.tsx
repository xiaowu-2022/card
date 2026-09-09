import { Head, router, useForm, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
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
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { SharedProps } from '@/types/global';

type TeamData = {
    members: { id: string; name: string; email: string; role: string; status: string }[];
    invitations: { id: string; email: string; role: string; status: string; expiresAt: string }[];
    roles: string[];
};
export default function Team({ team }: { team: TeamData }) {
    const form = useForm({ email: '', role: team.roles[0] ?? 'TENANT_ADMIN' });
    const canManage =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('admin_team.manage');
    return (
        <TenantAdminLayout>
            <Head title="Admin team" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Access control"
                    title="Admin team"
                    description="Tenant-scoped identities and memberships. Invitations are expiring, single-use, and revocable."
                />
                {canManage && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Invite administrator</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                className="grid max-w-2xl gap-4 sm:grid-cols-[minmax(0,1fr)_14rem_auto] sm:items-end"
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    form.post('/admin/team/invitations', {
                                        onSuccess: () => form.reset('email'),
                                    });
                                }}
                            >
                                <FormField
                                    id="invite-email"
                                    label="Email"
                                    error={form.errors.email}
                                >
                                    <Input
                                        id="invite-email"
                                        type="email"
                                        value={form.data.email}
                                        onChange={(event) =>
                                            form.setData('email', event.target.value)
                                        }
                                    />
                                </FormField>
                                <FormField id="invite-role" label="Role" error={form.errors.role}>
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
                                                    {role.replaceAll('_', ' ')}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </FormField>
                                <Button disabled={form.processing}>Send invite</Button>
                            </form>
                        </CardContent>
                    </Card>
                )}
                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Members</CardTitle>
                        </CardHeader>
                        <CardContent className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Administrator</TableHead>
                                        <TableHead>Role</TableHead>
                                        <TableHead>Status</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {team.members.map((member) => (
                                        <TableRow key={member.id}>
                                            <TableCell>
                                                <p className="font-medium">{member.name}</p>
                                                <p className="text-xs text-muted-foreground">
                                                    {member.email}
                                                </p>
                                            </TableCell>
                                            <TableCell>{member.role}</TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        member.status === 'ACTIVE'
                                                            ? 'SUCCESS'
                                                            : 'WARNING'
                                                    }
                                                    label={member.status}
                                                />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Invitations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {team.invitations.length === 0 ? (
                                <p className="text-sm text-muted-foreground">No invitations.</p>
                            ) : (
                                team.invitations.map((invite) => (
                                    <div
                                        key={invite.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div>
                                            <p className="font-medium">{invite.email}</p>
                                            <p className="text-sm text-muted-foreground">
                                                {invite.role} · {invite.status}
                                            </p>
                                        </div>
                                        {canManage && invite.status === 'PENDING' && (
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        router.post(
                                                            `/admin/team/invitations/${invite.id}/resend`,
                                                        )
                                                    }
                                                >
                                                    Resend
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="ghost"
                                                    onClick={() =>
                                                        router.post(
                                                            `/admin/team/invitations/${invite.id}/cancel`,
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ))
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </TenantAdminLayout>
    );
}
