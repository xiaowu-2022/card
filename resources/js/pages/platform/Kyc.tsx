import { RecordUserCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { useRef, useState } from 'react';
import type { SharedProps } from '@/types/global';
import { Button } from '@/components/ui/button';
import { KycApplicationDialog } from '@/components/admin/KycApplicationDialog';
import { Head, router, usePage } from '@inertiajs/react';
import { useAdminTranslation, t, dateTime, countryName } from '@/i18n/admin';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { StatusBadge } from '@/components/shared/StatusBadge';
import { PlatformLayout } from '@/layouts/PlatformLayout';

type Application = {
    userInfo?: UserInfo;
    userId?: string;
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
    const canReview =
        usePage<SharedProps>().props.auth.admin?.permissions.includes('kyc.review') ?? false;
    const [selection, setSelection] = useState<{ row: Application; review: boolean } | null>(null);
    const trigger = useRef<HTMLButtonElement | null>(null);
    return (
        <PlatformLayout
            title={t('KYC')}
            description={t('View identity information and document photos.')}
        >
            <Head title={t('KYC')} />
            <div className="space-y-4">
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    companies={companies}
                    page={applications}
                    filters={filters}
                    url="/platform/kyc"
                    searchLabel={t('Search email, phone or user ID')}
                    statuses={['PENDING', 'APPROVED', 'REJECTED', 'RESUBMISSION_REQUIRED']}
                    columns={[
                        {
                            label: 'Company / User',
                            render: (row) => <RecordUserCell row={row} />,
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
                                <div className="flex items-center gap-2 whitespace-nowrap">
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={(event) => {
                                            trigger.current = event.currentTarget;
                                            setSelection({ row, review: false });
                                        }}
                                    >
                                        {t('View details')}
                                    </Button>
                                    {canReview && row.reviewStatus === 'PENDING' && (
                                        <Button
                                            variant="secondary"
                                            size="sm"
                                            onClick={(event) => {
                                                trigger.current = event.currentTarget;
                                                setSelection({ row, review: true });
                                            }}
                                        >
                                            {t('Review')}
                                        </Button>
                                    )}
                                </div>
                            ),
                        },
                        { label: 'Submitted', render: (row) => dateTime(row.submittedAt) },
                    ]}
                />
            </div>
            {selection && (
                <KycApplicationDialog
                    companyId={selection.row.companyId}
                    applicationId={selection.row.id}
                    review={selection.review}
                    trigger={trigger}
                    onClose={() => setSelection(null)}
                    onChanged={() => router.reload({ only: ['applications'] })}
                />
            )}
        </PlatformLayout>
    );
}
