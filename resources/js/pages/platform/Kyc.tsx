import { Head, Link } from '@inertiajs/react';
import { useAdminTranslation, t, dateTime, countryName } from '@/i18n/admin';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { PlatformLayout } from '@/layouts/PlatformLayout';

type Application = {
    id: string;
    companyName: string;
    companyId: string;
    user: { displayName: string | null; contact: string | null };
    documentCountry: string;
    reviewStatus: string;
    submittedAt: string;
};

export default function Kyc({
    companies,
    applications,
    filters,
}: {
    companies: { id: string; name: string }[];
    applications: AccountPage<Application>;
    filters: { search?: string; status?: string; company?: string };
}) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('KYC')} />
            <div className="space-y-6">
                <PageHeader
                    eyebrow={t('Operations')}
                    title={t('KYC')}
                    description={t('View identity information and document photos.')}
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={applications}
                    filters={filters}
                    url="/platform/kyc"
                    searchLabel={t('Search email, phone or user ID')}
                    statuses={['PENDING', 'APPROVED', 'REJECTED', 'RESUBMISSION_REQUIRED']}
                    columns={[
                        { label: 'Tenant', render: (row) => row.companyName },
                        {
                            label: 'User',
                            render: (row) => (
                                <div>
                                    <p className="font-medium">{row.user.displayName ?? '—'}</p>
                                    <p className="text-xs text-muted-foreground">
                                        {row.user.contact ?? '—'}
                                    </p>
                                </div>
                            ),
                        },
                        {
                            label: 'Reference',
                            render: (row) => <span className="font-mono text-xs">{row.id}</span>,
                        },
                        {
                            label: 'Document country',
                            render: (row) => countryName(row.documentCountry),
                        },
                        {
                            label: 'Status',
                            render: (row) => (
                                <StatusBadge
                                    status={
                                        row.reviewStatus === 'APPROVED'
                                            ? 'SUCCESS'
                                            : row.reviewStatus === 'REJECTED'
                                              ? 'DANGER'
                                              : 'WARNING'
                                    }
                                    label={t(row.reviewStatus)}
                                />
                            ),
                        },
                        {
                            label: 'Actions',
                            render: (row) => (
                                <Link
                                    className="text-primary underline"
                                    href={`/platform/tenants/${row.companyId}/kyc/${row.id}`}
                                >
                                    {t('View details')}
                                </Link>
                            ),
                        },
                        { label: 'Submitted', render: (row) => dateTime(row.submittedAt) },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
