import { Head, Link, usePage } from '@inertiajs/react';
import { useAdminTranslation, t } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { Button } from '@/components/ui/button';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { displayMoney } from '@/lib/exact-amount';
import type { SharedProps } from '@/types/global';

type Wallet = {
    id: string;
    companyName: string;
    accountId: string;
    contact: string | null;
    status: string;
    asset: string;
    available: string;
    securityDeposit: string;
    held: string;
};

export default function Wallets({
    companies,
    wallets,
    filters,
}: {
    companies: { id: string; name: string }[];
    wallets: AccountPage<Wallet>;
    filters: { search?: string; company?: string };
}) {
    useAdminTranslation();
    const canReadTopups =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('wallet_topups.read');
    return (
        <PlatformLayout>
            <Head title={t('Wallet')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('Wallet')}
                    description={t(
                        'Read-only wallet balances. Top-up orders use the existing receipt confirmation flow.',
                    )}
                    actions={
                        <div className="flex flex-wrap gap-2">
                            {canReadTopups && (
                                <Button asChild>
                                    <Link
                                        href={
                                            filters.company
                                                ? `/platform/topups?company=${filters.company}`
                                                : '/platform/topups'
                                        }
                                    >
                                        {t('Top-up management')}
                                    </Link>
                                </Button>
                            )}
                        </div>
                    }
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={wallets}
                    filters={filters}
                    url="/platform/wallets"
                    searchLabel={t('Search account ID, email or phone')}
                    columns={[
                        { label: 'Tenant', render: (row) => row.companyName },
                        {
                            label: 'Account ID',
                            render: (row) => (
                                <div>
                                    <p className="font-mono">{row.accountId}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.contact ?? '—'}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            label: 'Status',
                            render: (row) => (
                                <StatusBadge
                                    status={row.status === 'ACTIVE' ? 'SUCCESS' : 'WARNING'}
                                    label={t(row.status)}
                                />
                            ),
                        },
                        {
                            label: 'Available balance',
                            render: (row) => `${displayMoney(row.available)} ${row.asset}`,
                        },
                        {
                            label: 'Security deposit',
                            render: (row) => `${displayMoney(row.securityDeposit)} ${row.asset}`,
                        },
                        {
                            label: 'Held amount',
                            render: (row) => `${displayMoney(row.held)} ${row.asset}`,
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
