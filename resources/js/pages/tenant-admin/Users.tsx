import { Head, Link } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Card, CardContent } from '@/components/ui/card';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

type UserSummary = {
    id: string;
    displayName: string | null;
    email: string | null;
    phone: string | null;
    status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED';
    verifiedChannel: string;
    createdAt: string;
    lastLoginAt: string | null;
};
type Props = { users: { data: UserSummary[]; current_page: number; last_page: number } };

export default function Users({ users }: Props) {
    return (
        <TenantAdminLayout>
            <Head title="Users" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Directory"
                    title="Users"
                    description="Tenant-scoped end-user identities and account status."
                />
                <Card>
                    <CardContent className="p-0">
                        {users.data.length === 0 ? (
                            <p className="p-8 text-center text-sm text-muted-foreground">
                                No registered users yet.
                            </p>
                        ) : (
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>User</TableHead>
                                        <TableHead>Contact</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>Verified</TableHead>
                                        <TableHead>Created</TableHead>
                                        <TableHead>Last login</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {users.data.map((user) => (
                                        <TableRow key={user.id}>
                                            <TableCell>
                                                <Link
                                                    className="font-semibold text-primary"
                                                    href={`/admin/users/${user.id}`}
                                                >
                                                    {user.displayName ?? 'Unnamed user'}
                                                </Link>
                                            </TableCell>
                                            <TableCell>{user.email ?? user.phone}</TableCell>
                                            <TableCell>
                                                <StatusBadge
                                                    status={
                                                        user.status === 'ACTIVE'
                                                            ? 'SUCCESS'
                                                            : user.status === 'SUSPENDED'
                                                              ? 'WARNING'
                                                              : 'DANGER'
                                                    }
                                                    label={user.status}
                                                />
                                            </TableCell>
                                            <TableCell>{user.verifiedChannel}</TableCell>
                                            <TableCell>
                                                {new Date(user.createdAt).toLocaleDateString()}
                                            </TableCell>
                                            <TableCell>
                                                {user.lastLoginAt
                                                    ? new Date(user.lastLoginAt).toLocaleString()
                                                    : 'Never'}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
