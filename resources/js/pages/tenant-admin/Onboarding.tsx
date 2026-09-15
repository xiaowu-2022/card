import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { useAdminTranslation, t } from '@/i18n/admin';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Circle, LockKeyhole } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { CompanyConfigurationLayout as TenantAdminLayout } from '@/components/admin/CompanyConfiguration';
import type { SharedProps } from '@/types/global';

type Item = { key: string; label: string; complete: boolean; required: boolean };
export default function Onboarding({
    tenantRecord,
    onboarding,
}: {
    tenantRecord: { name: string; status: 'DRAFT' | 'ACTIVE' | 'SUSPENDED' | 'CLOSED' };
    onboarding: { foundation_ready: boolean; business_ready: boolean; items: Item[] };
}) {
    useAdminTranslation();
    const configurationUrl = useCompanyConfigurationUrl();
    const required = onboarding.items.filter((item) => item.required);
    const later = onboarding.items.filter((item) => !item.required);
    const configurationProps = usePage<
        SharedProps & { configurationBase?: string; configurationReadOnly?: boolean }
    >().props;
    const canActivate =
        Boolean(configurationProps.configurationBase) &&
        configurationProps.auth.admin?.permissions.includes('tenant.manage');
    return (
        <TenantAdminLayout>
            <Head title={t('Setup')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Tenant setup')}
                    title={t('{{value1}} foundation', { value1: tenantRecord.name })}
                    description={t(
                        'Complete the administrative requirements, then activate tenant access. Business readiness is a separate future milestone.',
                    )}
                    actions={
                        <StatusBadge
                            status={tenantRecord.status === 'ACTIVE' ? 'SUCCESS' : 'NEUTRAL'}
                            label={t(tenantRecord.status)}
                        />
                    }
                />
                <Alert>
                    <LockKeyhole className="size-5" />
                    <AlertTitle>{t('Activation is deliberately narrow')}</AlertTitle>
                    <AlertDescription>
                        {t(
                            'Activating this foundation does not enable cards, wallets, deposits, KYC applications, products, or providers.',
                        )}
                    </AlertDescription>
                </Alert>
                <div className="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(18rem,1fr)]">
                    <Card>
                        <CardHeader>
                            <CardTitle>{t('Required foundation')}</CardTitle>
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
                                        <span className="font-medium">{t(item.label)}</span>
                                    </div>
                                    <StatusBadge
                                        status={item.complete ? 'SUCCESS' : 'WARNING'}
                                        label={item.complete ? t('Complete') : t('Required')}
                                    />
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                    <div className="space-y-6">
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Foundation activation')}</CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-4">
                                <p className="text-sm text-muted-foreground">
                                    {t(
                                        'All required checks are computed from persisted state. There is no manual readiness override.',
                                    )}
                                </p>
                                {tenantRecord.status === 'DRAFT' && canActivate ? (
                                    <Button
                                        className="w-full"
                                        disabled={!onboarding.foundation_ready}
                                        data-config-write
                                        onClick={() =>
                                            router.post(
                                                configurationUrl('/admin/onboarding/activate'),
                                            )
                                        }
                                    >
                                        {t('Activate foundation')}
                                    </Button>
                                ) : tenantRecord.status === 'ACTIVE' ? (
                                    <StatusBadge status="SUCCESS" label={t('Foundation active')} />
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        {t('Tenant Owner permission is required to activate.')}
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                        <Card>
                            <CardHeader>
                                <CardTitle>{t('Configure')}</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-2">
                                <Button asChild variant="secondary">
                                    <Link href={configurationUrl('/admin/settings/branding')}>
                                        {t('Branding and settings')}
                                    </Link>
                                </Button>
                                <Button asChild variant="secondary">
                                    <Link href={configurationUrl('/admin/team')}>{t('Team')}</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    </div>
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Later business readiness')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        {later.map((item) => (
                            <div
                                key={item.key}
                                className="flex items-center gap-3 rounded-lg border border-dashed p-4 text-muted-foreground"
                            >
                                <Circle className="size-5" />
                                <span>{t(item.label)}</span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
