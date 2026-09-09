import { Head, Link, router, usePage } from '@inertiajs/react';
import { Check, Circle } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import type { SharedProps } from '@/types/global';

type CheckItem = { key: string; label: string; complete: boolean; required: boolean };
type Detail = {
    id: string;
    name: string;
    slug: string;
    status: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED';
    defaultLocale: string;
    timezone: string;
    defaultAsset: string;
    createdAt: string;
    settings: {
        brandName: string;
        primaryColor: string;
        securityDepositAmount: string;
        securityDepositAsset: string;
        kycEnabled: boolean;
        kycReviewMode: string;
    };
    domains: { id: string; hostname: string; type: string; status: string; primary: boolean }[];
    admins: { name: string; email: string; role: string; status: string }[];
    invitations: { id: string; email: string; role: string; status: string; expiresAt: string }[];
    onboarding: { foundation_ready: boolean; business_ready: boolean; items: CheckItem[] };
};
const tones: Record<Detail['status'], StatusTone> = {
    DRAFT: 'NEUTRAL',
    ACTIVE: 'SUCCESS',
    SUSPENDED: 'WARNING',
    CLOSED: 'DANGER',
};

export default function TenantDetail({ tenantRecord: tenant }: { tenantRecord: Detail }) {
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canManage = permissions.includes('tenant.manage');
    const canManageTeam = permissions.includes('admin_team.manage');
    const lifecycle =
        canManage && tenant.status === 'ACTIVE' ? (
            <AlertDialog>
                <AlertDialogTrigger asChild>
                    <Button variant="destructive">Suspend tenant</Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogTitle className="text-lg font-semibold">
                        Suspend {tenant.name}?
                    </AlertDialogTitle>
                    <AlertDialogDescription className="mt-2 text-sm text-muted-foreground">
                        Current status: ACTIVE. Registration and business access will be blocked.
                        Historical users, balances, cards, and Ledger records are never deleted or
                        modified by this lifecycle action.
                    </AlertDialogDescription>
                    <div className="mt-6 flex justify-end gap-2">
                        <AlertDialogCancel asChild>
                            <Button variant="secondary">Cancel</Button>
                        </AlertDialogCancel>
                        <AlertDialogAction asChild>
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.post(`/platform/tenants/${tenant.id}/suspend`)
                                }
                            >
                                Suspend tenant
                            </Button>
                        </AlertDialogAction>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
        ) : canManage && tenant.status === 'SUSPENDED' ? (
            <Button onClick={() => router.post(`/platform/tenants/${tenant.id}/reactivate`)}>
                Reactivate tenant
            </Button>
        ) : null;
    return (
        <PlatformLayout>
            <Head title={tenant.name} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Tenant record"
                    title={tenant.name}
                    description={`${tenant.slug} · Created ${new Date(tenant.createdAt).toLocaleDateString()}`}
                    actions={
                        <>
                            <StatusBadge status={tones[tenant.status]} label={tenant.status} />
                            {lifecycle}
                            <Button asChild variant="secondary">
                                <Link href="/platform/tenants">Back</Link>
                            </Button>
                        </>
                    }
                />
                {!tenant.onboarding.business_ready && (
                    <Alert>
                        <AlertTitle>Administrative foundation only</AlertTitle>
                        <AlertDescription>
                            Foundation readiness does not mean the card business is ready. Provider,
                            product, funding and ledger capabilities belong to later phases.
                        </AlertDescription>
                    </Alert>
                )}
                <div className="grid gap-6 xl:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>Configuration</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">Locale:</span>{' '}
                                {tenant.defaultLocale}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Timezone:</span>{' '}
                                {tenant.timezone}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Default asset:</span>{' '}
                                {tenant.defaultAsset}
                            </p>
                            <p>
                                <span className="text-muted-foreground">Brand:</span>{' '}
                                {tenant.settings.brandName}{' '}
                                <span
                                    className="inline-block size-3 rounded-full border align-middle"
                                    style={{ backgroundColor: tenant.settings.primaryColor }}
                                />
                            </p>
                            <p>
                                <span className="text-muted-foreground">Deposit requirement:</span>{' '}
                                {tenant.settings.securityDepositAmount}{' '}
                                {tenant.settings.securityDepositAsset}
                            </p>
                            <p>
                                <span className="text-muted-foreground">KYC policy:</span>{' '}
                                {tenant.settings.kycEnabled ? 'Enabled' : 'Disabled'} ·{' '}
                                {tenant.settings.kycReviewMode}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle>Foundation checklist</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-3 sm:grid-cols-2">
                            {tenant.onboarding.items.map((item) => (
                                <div key={item.key} className="flex gap-3 rounded-lg border p-3">
                                    {item.complete ? (
                                        <Check className="mt-0.5 size-4 text-success" />
                                    ) : (
                                        <Circle className="mt-0.5 size-4 text-muted-foreground" />
                                    )}
                                    <div>
                                        <p className="text-sm font-medium">{item.label}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.required ? 'Required' : 'Later phase'}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Domains</CardTitle>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Hostname</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Primary</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenant.domains.map((domain) => (
                                    <TableRow key={domain.id}>
                                        <TableCell>{domain.hostname}</TableCell>
                                        <TableCell>{domain.type}</TableCell>
                                        <TableCell>{domain.status}</TableCell>
                                        <TableCell>{domain.primary ? 'Yes' : 'No'}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Administrators</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {tenant.admins.length ? (
                                tenant.admins.map((admin) => (
                                    <div key={admin.email} className="rounded-lg border p-3">
                                        <p className="font-medium">{admin.name}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {admin.email} · {admin.role} · {admin.status}
                                        </p>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No accepted administrators yet.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>Invitations</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {tenant.invitations.length ? (
                                tenant.invitations.map((invitation) => (
                                    <div
                                        key={invitation.id}
                                        className="flex flex-wrap items-center justify-between gap-3 rounded-lg border p-3"
                                    >
                                        <div>
                                            <p className="font-medium">{invitation.email}</p>
                                            <p className="text-sm text-muted-foreground">
                                                {invitation.role} · {invitation.status}
                                            </p>
                                        </div>
                                        {canManageTeam && invitation.status === 'PENDING' && (
                                            <div className="flex gap-2">
                                                <Button
                                                    size="sm"
                                                    variant="secondary"
                                                    onClick={() =>
                                                        router.post(
                                                            `/platform/tenants/${tenant.id}/invitations/${invitation.id}/resend`,
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
                                                            `/platform/tenants/${tenant.id}/invitations/${invitation.id}/cancel`,
                                                        )
                                                    }
                                                >
                                                    Cancel
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">No invitations.</p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </PlatformLayout>
    );
}
