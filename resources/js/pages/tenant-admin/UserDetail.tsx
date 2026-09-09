import { Head, Link, router, usePage } from '@inertiajs/react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { SharedProps } from '@/types/global';

type UserDetail = {
    id: string;
    displayName: string | null;
    email: string | null;
    phone: string | null;
    status: 'ACTIVE' | 'SUSPENDED' | 'DISABLED';
    verifiedChannel: string;
    locale: string | null;
    createdAt: string;
    lastLoginAt: string | null;
};
type Props = { user: UserDetail; audit: Array<{ id: string; action: string; createdAt: string }> };

export default function UserDetailPage({ user, audit }: Props) {
    const canSuspend =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('users.suspend') ?? false;
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const action =
        user.status === 'ACTIVE' ? 'suspend' : user.status === 'SUSPENDED' ? 'reactivate' : null;
    return (
        <TenantAdminLayout>
            <Head title={user.displayName ?? 'User'} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="User"
                    title={user.displayName ?? 'Unnamed user'}
                    description={user.email ?? user.phone ?? ''}
                    actions={
                        canSuspend && action ? (
                            <AlertDialog>
                                <AlertDialogTrigger asChild>
                                    <Button
                                        variant={action === 'suspend' ? 'destructive' : 'secondary'}
                                    >
                                        {action === 'suspend' ? 'Suspend user' : 'Reactivate user'}
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <div className="space-y-2">
                                        <AlertDialogTitle className="text-lg font-semibold">
                                            {action === 'suspend'
                                                ? 'Suspend this user?'
                                                : 'Reactivate this user?'}
                                        </AlertDialogTitle>
                                        <AlertDialogDescription className="text-sm text-muted-foreground">
                                            {action === 'suspend'
                                                ? 'The user will retain their account but only have restricted access. This does not change any financial or card data.'
                                                : 'Normal account access will be restored.'}
                                        </AlertDialogDescription>
                                    </div>
                                    <div className="mt-6 flex justify-end gap-2">
                                        <AlertDialogCancel asChild>
                                            <Button variant="secondary">Cancel</Button>
                                        </AlertDialogCancel>
                                        <AlertDialogAction asChild>
                                            <Button
                                                onClick={() =>
                                                    router.post(`/admin/users/${user.id}/${action}`)
                                                }
                                            >
                                                Confirm
                                            </Button>
                                        </AlertDialogAction>
                                    </div>
                                </AlertDialogContent>
                            </AlertDialog>
                        ) : undefined
                    }
                />
                <Tabs defaultValue="overview">
                    <TabsList>
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="account">Account</TabsTrigger>
                        <TabsTrigger value="audit">Audit</TabsTrigger>
                    </TabsList>
                    <TabsContent value="overview">
                        <Card>
                            <CardHeader>
                                <CardTitle>Overview</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">Status</p>
                                    <div className="mt-1">
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
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        Verified channel
                                    </p>
                                    <p className="mt-1 font-medium">{user.verifiedChannel}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Created</p>
                                    <p className="mt-1 font-medium">
                                        {new Date(user.createdAt).toLocaleString()}
                                    </p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">Last login</p>
                                    <p className="mt-1 font-medium">
                                        {user.lastLoginAt
                                            ? new Date(user.lastLoginAt).toLocaleString()
                                            : 'Never'}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="account">
                        <Card>
                            <CardHeader>
                                <CardTitle>Account</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <p>Email: {user.email ?? 'Not added'}</p>
                                <p>Phone: {user.phone ?? 'Not added'}</p>
                                <p>Locale: {user.locale ?? 'Tenant default'}</p>
                                <div className="flex flex-wrap gap-2 pt-2">
                                    {permissions.includes('wallet.read') && (
                                        <Button asChild variant="secondary" size="sm">
                                            <Link href={`/admin/users/${user.id}/wallet`}>
                                                View wallet
                                            </Link>
                                        </Button>
                                    )}
                                    {permissions.includes('ledger.read') && (
                                        <Button asChild variant="secondary" size="sm">
                                            <Link href={`/admin/users/${user.id}/ledger`}>
                                                View ledger
                                            </Link>
                                        </Button>
                                    )}
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="audit">
                        <Card>
                            <CardHeader>
                                <CardTitle>Audit history</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {audit.length ? (
                                    audit.map((event) => (
                                        <div
                                            key={event.id}
                                            className="flex justify-between border-b pb-3 text-sm last:border-0"
                                        >
                                            <span className="font-medium">
                                                {event.action.replaceAll('_', ' ')}
                                            </span>
                                            <span className="text-muted-foreground">
                                                {new Date(event.createdAt).toLocaleString()}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No user lifecycle events recorded.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>
                </Tabs>
            </div>
        </TenantAdminLayout>
    );
}
