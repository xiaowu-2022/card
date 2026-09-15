import { useAdminTranslation, t } from '@/i18n/admin';
import { Head, Link } from '@inertiajs/react';
import { FileCheck2, Settings, Users, WalletCards } from 'lucide-react';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';

const areas = [
    {
        label: 'Users',
        description: 'Open the Tenant-scoped user directory.',
        href: '/admin/users',
        icon: Users,
    },
    {
        label: 'KYC review',
        description: 'Review pending identity applications.',
        href: '/admin/kyc',
        icon: FileCheck2,
    },
    {
        label: 'Wallets',
        description: 'Open a user record to inspect Wallet and Ledger data read-only.',
        href: '/admin/users',
        icon: WalletCards,
    },
    {
        label: 'Settings',
        description: 'Manage branding, locale, business, and KYC requirements.',
        href: '/admin/settings/branding',
        icon: Settings,
    },
];

export default function Dashboard() {
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('Tenant admin')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Tenant administration')}
                    title={t('Overview')}
                    description={t(
                        'Operate this tenant within explicit permission and financial safety boundaries.',
                    )}
                />
                <div className="grid gap-4 sm:grid-cols-2">
                    {areas.map((area) => (
                        <Card key={area.label}>
                            <CardHeader>
                                <div className="flex items-center gap-3">
                                    <div className="grid size-10 place-items-center rounded-lg bg-muted">
                                        <area.icon className="size-5" />
                                    </div>
                                    <CardTitle>{t(area.label)}</CardTitle>
                                </div>
                            </CardHeader>
                            <CardContent>
                                <p className="text-sm text-muted-foreground">
                                    {t(area.description)}
                                </p>
                                <Button asChild variant="secondary" size="sm" className="mt-4">
                                    <Link href={area.href}>{t('Open')}</Link>
                                </Button>
                            </CardContent>
                        </Card>
                    ))}
                </div>
                <Card>
                    <CardHeader>
                        <CardTitle>{t('Financial controls')}</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-3 text-sm text-muted-foreground sm:grid-cols-3">
                        <p>{t('Wallet and Ledger access is read-only.')}</p>
                        <p>{t('No administrator can alter balances.')}</p>
                        <p>{t('Ledger history is immutable and reconciled from Postings.')}</p>
                    </CardContent>
                </Card>
            </div>
        </TenantAdminLayout>
    );
}
