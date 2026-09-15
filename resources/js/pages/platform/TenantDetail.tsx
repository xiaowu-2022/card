import { displayMoney } from '@/lib/exact-amount';
import { useAdminTranslation, t, dateTime } from '@/i18n/admin';
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
import { CompanyDepositSettings } from '@/components/admin/CompanyDepositSettings';

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
        securityDepositRefundWaitDays: number | null;
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
    useAdminTranslation();
    const permissions = usePage<SharedProps>().props.auth.admin?.permissions ?? [];
    const canManage = permissions.includes('tenant.manage');
    const canManageTeam = permissions.includes('admin_team.manage');
    const lifecycle =
        canManage && tenant.status === 'ACTIVE' ? (
            <AlertDialog>
                <AlertDialogTrigger asChild>
                    <Button variant="destructive">{t('Suspend tenant')}</Button>
                </AlertDialogTrigger>
                <AlertDialogContent>
                    <AlertDialogTitle className="text-lg font-semibold">
                        {t('Suspend {{value1}}?', { value1: tenant.name })}
                    </AlertDialogTitle>
                    <AlertDialogDescription className="mt-2 text-sm text-muted-foreground">
                        {t(
                            'Current status: ACTIVE. Registration and business access will be blocked. Historical users, balances, cards, and Ledger records are never deleted or modified by this lifecycle action.',
                        )}
                    </AlertDialogDescription>
                    <div className="mt-6 flex justify-end gap-2">
                        <AlertDialogCancel asChild>
                            <Button variant="secondary">{t('Cancel')}</Button>
                        </AlertDialogCancel>
                        <AlertDialogAction asChild>
                            <Button
                                variant="destructive"
                                onClick={() =>
                                    router.post(`/platform/tenants/${tenant.id}/suspend`)
                                }
                            >
                                {t('Suspend tenant')}
                            </Button>
                        </AlertDialogAction>
                    </div>
                </AlertDialogContent>
            </AlertDialog>
        ) : canManage && tenant.status === 'SUSPENDED' ? (
            <Button onClick={() => router.post(`/platform/tenants/${tenant.id}/reactivate`)}>
                {t('Reactivate tenant')}
            </Button>
        ) : null;
    return (
        <PlatformLayout>
            {canManage && (
                <div className="mb-4">
                    <Button asChild>
                        <Link href={`/platform/tenants/${tenant.id}/configuration/card-products`}>
                            {t('Configure company')}
                        </Link>
                    </Button>
                </div>
            )}
            <Head title={tenant.name} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Tenant record')}
                    title={tenant.name}
                    description={t('{{value1}} · Created {{value2}}', {
                        value1: tenant.slug,
                        value2: dateTime(tenant.createdAt),
                    })}
                    actions={
                        <>
                            <StatusBadge status={tones[tenant.status]} label={t(tenant.status)} />
                            {canManage && tenant.status === 'DRAFT' && (
                                <Button asChild>
                                    <Link
                                        href={`/platform/tenants/${tenant.id}/configuration/onboarding`}
                                    >
                                        {t('Onboarding')}
                                    </Link>
                                </Button>
                            )}
                            {lifecycle}
                            {permissions.includes('wallet_topups.read') && (
                                <Button asChild variant="secondary">
                                    <Link href={`/platform/tenants/${tenant.id}/topups`}>
                                        {t('Top-ups')}
                                    </Link>
                                </Button>
                            )}
                            <Button asChild variant="secondary">
                                <Link href="/platform/tenants">{t('Back')}</Link>
                            </Button>
                        </>
                    }
                />
                {!tenant.onboarding.business_ready && (
                    <Alert>
                        <AlertTitle>{t('Administrative foundation only')}</AlertTitle>
                        <AlertDescription>
                            {t(
                                'Foundation readiness does not mean the card business is ready. Provider, product, funding and ledger capabilities belong to later phases.',
                            )}
                        </AlertDescription>
                    </Alert>
                )}
                <CompanyDepositSettings
                    companyId={tenant.id}
                    amount={tenant.settings.securityDepositAmount}
                    asset={tenant.settings.securityDepositAsset}
                    waitDays={tenant.settings.securityDepositRefundWaitDays}
                    canManage={canManage}
                />
                <div className="grid gap-6 xl:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Configuration')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                <span className="text-muted-foreground">{t('Locale:')}</span>{' '}
                                {t(tenant.defaultLocale)}
                            </p>
                            <p>
                                <span className="text-muted-foreground">{t('Timezone:')}</span>{' '}
                                {tenant.timezone}
                            </p>
                            <p>
                                <span className="text-muted-foreground">{t('Default asset:')}</span>{' '}
                                {tenant.defaultAsset}
                            </p>
                            <p>
                                <span className="text-muted-foreground">{t('Brand:')}</span>{' '}
                                {tenant.settings.brandName}{' '}
                                <span
                                    className="inline-block size-3 rounded-full border align-middle"
                                    style={{ backgroundColor: tenant.settings.primaryColor }}
                                />
                            </p>
                            <p>
                                <span className="text-muted-foreground">
                                    {t('Deposit requirement:')}
                                </span>{' '}
                                {displayMoney(tenant.settings.securityDepositAmount)}{' '}
                                {tenant.settings.securityDepositAsset}
                            </p>
                            <p>
                                <span className="text-muted-foreground">{t('KYC policy:')}</span>{' '}
                                {tenant.settings.kycEnabled ? t('Enabled') : t('Disabled')} ·{' '}
                                {tenant.settings.kycReviewMode}
                            </p>
                        </CardContent>
                    </Card>
                    <Card className="xl:col-span-2">
                        <CardHeader>
                            <CardTitle>{t('Foundation checklist')}</CardTitle>
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
                                        <p className="text-sm font-medium">{t(item.label)}</p>
                                        <p className="text-xs text-muted-foreground">
                                            {item.required ? t('Required') : t('Later phase')}
                                        </p>
                                    </div>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Domains')}</CardTitle>
                        {canManage && (
                            <Button asChild variant="secondary" className="w-fit">
                                <Link href={`/platform/tenants/${tenant.id}/domains`}>
                                    {t('Domains')}
                                </Link>
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>{t('Hostname')}</TableHead>
                                    <TableHead>{t('Type')}</TableHead>
                                    <TableHead>{t('Status')}</TableHead>
                                    <TableHead>{t('Primary')}</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenant.domains.map((domain) => (
                                    <TableRow key={domain.id}>
                                        <TableCell>{domain.hostname}</TableCell>
                                        <TableCell>{t(domain.type)}</TableCell>
                                        <TableCell>{t(domain.status)}</TableCell>
                                        <TableCell>{domain.primary ? t('Yes') : t('No')}</TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
                <div className="grid gap-6 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Administrators')}</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {tenant.admins.length ? (
                                tenant.admins.map((admin) => (
                                    <div key={admin.email} className="rounded-lg border p-3">
                                        <p className="font-medium">{admin.name}</p>
                                        <p className="text-sm text-muted-foreground">
                                            {admin.email} · {t(admin.role)} · {t(admin.status)}
                                        </p>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('No accepted administrators yet.')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Invitations')}</CardTitle>
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
                                                {t(invitation.role)} · {t(invitation.status)}
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
                                                    {t('Resend')}
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
                                                    {t('Cancel')}
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    {t('No invitations.')}
                                </p>
                            )}
                        </CardContent>
                    </Card>
                </div>
            </div>
        </PlatformLayout>
    );
}
