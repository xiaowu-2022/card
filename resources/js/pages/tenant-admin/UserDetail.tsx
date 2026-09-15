import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
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
    useAdminTranslation();
    const canSuspend =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('users.suspend') ?? false;
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const action =
        user.status === 'ACTIVE' ? 'suspend' : user.status === 'SUSPENDED' ? 'reactivate' : null;
    return (
        <TenantAdminLayout>
            <Head title={user.displayName ?? t('User')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('User')}
                    title={user.displayName ?? t('Unnamed user')}
                    description={user.email ?? user.phone ?? ''}
                    actions={
                        canSuspend && action ? (
                            <AlertDialog>
                                <AlertDialogTrigger asChild>
                                    <Button
                                        variant={action === 'suspend' ? 'destructive' : 'secondary'}
                                    >
                                        {action === 'suspend'
                                            ? t('Suspend user')
                                            : t('Reactivate user')}
                                    </Button>
                                </AlertDialogTrigger>
                                <AlertDialogContent>
                                    <div className="space-y-2">
                                        <AlertDialogTitle className="text-lg font-semibold">
                                            {action === 'suspend'
                                                ? t('Suspend this user?')
                                                : t('Reactivate this user?')}
                                        </AlertDialogTitle>
                                        <AlertDialogDescription className="text-sm text-muted-foreground">
                                            {action === 'suspend'
                                                ? t(
                                                      'The user will retain their account but only have restricted access. This does not change any financial or card data.',
                                                  )
                                                : t('Normal account access will be restored.')}
                                        </AlertDialogDescription>
                                    </div>
                                    <div className="mt-6 flex justify-end gap-2">
                                        <AlertDialogCancel asChild>
                                            <Button variant="secondary">{t('Cancel')}</Button>
                                        </AlertDialogCancel>
                                        <AlertDialogAction asChild>
                                            <Button
                                                onClick={() =>
                                                    router.post(`/admin/users/${user.id}/${action}`)
                                                }
                                            >
                                                {t('Confirm')}
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
                        <TabsTrigger value="overview">{t('Overview')}</TabsTrigger>
                        <TabsTrigger value="account">{t('Account')}</TabsTrigger>
                        <TabsTrigger value="audit">{t('Audit')}</TabsTrigger>
                    </TabsList>
                    <TabsContent value="overview">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Overview')}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-5 sm:grid-cols-2">
                                <div>
                                    <p className="text-sm text-muted-foreground">{t('Status')}</p>
                                    <div className="mt-1">
                                        <StatusBadge
                                            status={
                                                user.status === 'ACTIVE'
                                                    ? 'SUCCESS'
                                                    : user.status === 'SUSPENDED'
                                                      ? 'WARNING'
                                                      : 'DANGER'
                                            }
                                            label={t(user.status)}
                                        />
                                    </div>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        {t('Verified channel')}
                                    </p>
                                    <p className="mt-1 font-medium">{t(user.verifiedChannel)}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">{t('Created')}</p>
                                    <p className="mt-1 font-medium">{dateTime(user.createdAt)}</p>
                                </div>
                                <div>
                                    <p className="text-sm text-muted-foreground">
                                        {t('Last login')}
                                    </p>
                                    <p className="mt-1 font-medium">
                                        {user.lastLoginAt ? dateTime(user.lastLoginAt) : t('Never')}
                                    </p>
                                </div>
                            </CardContent>
                        </Card>
                    </TabsContent>
                    <TabsContent value="account">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Account')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3 text-sm">
                                <p>
                                    {t('Email: {{value1}}', {
                                        value1: user.email ?? t('Not added'),
                                    })}
                                </p>
                                <p>
                                    {t('Phone: {{value1}}', {
                                        value1: user.phone ?? t('Not added'),
                                    })}
                                </p>
                                <p>
                                    {t('Locale: {{value1}}', {
                                        value1: user.locale ?? t('Tenant default'),
                                    })}
                                </p>
                                <div className="flex flex-wrap gap-2 pt-2">
                                    {permissions.includes('wallet.read') && (
                                        <Button asChild variant="secondary" size="sm">
                                            <Link href={`/admin/users/${user.id}/wallet`}>
                                                {t('View wallet')}
                                            </Link>
                                        </Button>
                                    )}
                                    {permissions.includes('ledger.read') && (
                                        <Button asChild variant="secondary" size="sm">
                                            <Link href={`/admin/users/${user.id}/ledger`}>
                                                {t('View ledger')}
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
                                <CardTitle>{t('Audit history')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {audit.length ? (
                                    audit.map((event) => (
                                        <div
                                            key={event.id}
                                            className="flex justify-between border-b pb-3 text-sm last:border-0"
                                        >
                                            <span className="font-medium">{t(event.action)}</span>
                                            <span className="text-muted-foreground">
                                                {dateTime(event.createdAt)}
                                            </span>
                                        </div>
                                    ))
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t('No user lifecycle events recorded.')}
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
