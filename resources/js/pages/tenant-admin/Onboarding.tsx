import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, LockKeyhole } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import type { SharedProps } from '@/types/global';

type Item = { key: string; label: string; complete: boolean; required: boolean };
export default function Onboarding({
    tenantRecord,
    onboarding,
}: {
    tenantRecord: { name: string; status: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED' };
    onboarding: { foundation_ready: boolean; business_ready: boolean; items: Item[] };
}) {
    const required = onboarding.items.filter((item) => item.required);
    const later = onboarding.items.filter((item) => !item.required);
    const canActivate =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('tenant.activate');
    return (
        <TenantAdminLayout>
            <Head title="Setup" />
            <div className="space-y-6">
                <PageHeader
                    eyebrow="Tenant setup"
                    title={`${tenantRecord.name} foundation`}
                    description="Complete the administrative requirements, then activate tenant access. Business readiness is a separate future milestone."
                    actions={
                        <StatusBadge
                            status={tenantRecord.status === 'ACTIVE' ? 'SUCCESS' : 'NEUTRAL'}
                            label={tenantRecord.status}
                        />
                    }
                />
                <Alert>
                    <LockKeyhole className="size-5" />
                    <AlertTitle>Activation is deliberately narrow</AlertTitle>
                    <AlertDescription>
                        Activating this foundation does not enable cards, wallets, deposits, KYC
                        applications, products, or providers.
                    </AlertDescription>
                </Alert>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>Required foundation</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {required.map((item) => (
                                <div
                                    key={item.key}
                                    className="flex items-center justify-between gap-4 rounded-lg border p-4"
                                >
                                    <div className="flex items-center gap-3">
                                        {item.complete ? (
                                            <CheckCircle2 className="size-5 text-success" />
                                        ) : (
                                            <Circle className="size-5 text-muted-foreground" />
                                        )}
                                        <span className="font-medium">{item.label}</span>
                                    </div>
                                    <StatusBadge
                                        status={item.complete ? 'SUCCESS' : 'WARNING'}
                                        label={item.complete ? 'Complete' : 'Required'}
                                    />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>Foundation activation</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    All required checks are computed from persisted state. There is
                                    no manual readiness override.
                                </p>
                                {tenantRecord.status === 'DRAFT' && canActivate ? (
                                    <Button
                                        className="w-full"
                                        disabled={!onboarding.foundation_ready}
                                        onClick={() => router.post('/admin/onboarding/activate')}
                                    >
                                        Activate foundation
                                    </Button>
                                ) : tenantRecord.status === 'ACTIVE' ? (
                                    <StatusBadge status="SUCCESS" label="Foundation active" />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        Tenant Owner permission is required to activate.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>Configure</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                <Button asChild variant="secondary">
                                    <Link href="/admin/settings/branding">
                                        Branding and settings
                                    </Link>
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href="/admin/domains">Domains</Link>
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href="/admin/team">Team</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>Later business readiness</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        {later.map((item) => (
                            <div
                                key={item.key}
                                className="flex items-center gap-3 rounded-lg border border-dashed p-4 text-muted-foreground"
                            >
                                <Circle className="size-5" />
                                <span>{item.label}</span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
