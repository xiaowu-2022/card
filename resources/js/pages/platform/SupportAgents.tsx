import { Head } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { PlatformSupportTabs } from '@/components/support/PlatformSupportTabs';
import { SupportProfile } from '@/components/support/SupportProfile';
import { t, useAdminTranslation } from '@/i18n/admin';
type Agent = { id: string; name: string; email: string; supportName: string | null };
export default function SupportAgents({
    agents,
    filters,
}: {
    agents: AccountPage<Agent>;
    filters: { search?: string };
}) {
    useAdminTranslation();
    return (
        <PlatformLayout>
            <Head title={t('Support staff')} />
            <div className="space-y-5">
                <PageHeader title={t('Customer support')} />
                <PlatformSupportTabs agents />
                <p className="text-sm text-muted-foreground">
                    {t(
                        'A blank nickname displays Customer support. Changes apply to future messages only.',
                    )}
                </p>
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    page={agents}
                    filters={filters}
                    url="/platform/support/agents"
                    searchLabel={t('Search name or email')}
                    columns={[
                        { label: 'Name', render: (a) => a.name },
                        {
                            label: 'Email',
                            className: 'w-64 max-w-64',
                            render: (a) => (
                                <span className="block w-56 truncate" title={a.email}>
                                    {a.email}
                                </span>
                            ),
                        },
                        {
                            label: 'Support nickname',
                            render: (a) => (
                                <SupportProfile
                                    key={a.id + ':' + a.supportName}
                                    name={a.supportName}
                                    url={`/platform/support/agents/${a.id}`}
                                />
                            ),
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
