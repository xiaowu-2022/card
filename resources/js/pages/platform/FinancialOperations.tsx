import { Head } from '@inertiajs/react';
import { t, useAdminTranslation, dateTime } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import type { ManualOperation } from '@/components/admin/ManualOperationHistory';

export default function FinancialOperations({
    operations,
    companies,
    filters,
}: {
    operations: AccountPage<ManualOperation>;
    companies: { id: string; name: string }[];
    filters: { company?: string; search?: string };
}) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('Financial operation records')} />
            <div className="space-y-6">
                <PageHeader
                    title={t('Financial operation records')}
                    description={t(
                        'Read-only history of manual top-up and withdrawal operations. Operator identity and operation time are retained.',
                    )}
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    page={operations}
                    companies={companies}
                    filters={filters}
                    url="/platform/financial-operations"
                    searchLabel={t('Search operator or order ID')}
                    columns={[
                        { label: 'Company', render: (row) => row.companyName ?? '—' },
                        {
                            label: 'Order',
                            render: (row) => (
                                <div className="max-w-64 break-all text-xs">{row.orderId}</div>
                            ),
                        },
                        { label: 'Operation', render: (row) => t(row.action) },
                        {
                            label: 'Operator',
                            render: (row) => (
                                <div>
                                    {row.operatorName ?? t('Unknown operator')}
                                    <p className="max-w-56 break-all text-xs text-muted-foreground">
                                        {row.operatorId}
                                    </p>
                                </div>
                            ),
                        },
                        { label: 'Operation time', render: (row) => dateTime(row.operatedAt) },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
