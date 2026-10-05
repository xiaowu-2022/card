import { Head, Link } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n/admin';
import { useEffect, useState } from 'react';
import { SettingsTabs } from '@/components/admin/SettingsTabs';
import {
    companySettings,
    companySection,
    companySettingsUrl,
    companySectionEvent,
} from '@/components/admin/company-settings';
type Company = {
    id: string;
    name: string;
    slug: string;
    status: string;
    default_locale: string;
    timezone: string;
};
export default function CompanyConfigurations({
    records,
    companies,
    filters,
}: {
    records: AccountPage<Company>;
    companies: { id: string; name: string }[];
    filters: Record<string, string>;
}) {
    const [section, setSection] = useState(() =>
        companySection(new URL(location.href).searchParams.get('section')),
    );
    useEffect(() => {
        const sync = () =>
            setSection(companySection(new URL(location.href).searchParams.get('section')));
        window.addEventListener('popstate', sync);
        window.addEventListener(companySectionEvent, sync);
        return () => {
            window.removeEventListener('popstate', sync);
            window.removeEventListener(companySectionEvent, sync);
        };
    }, []);
    return (
        <PlatformLayout title={t('Company configuration')}>
            <Head title={t('Company configuration')} />
            <div className="space-y-4">
                <SettingsTabs
                    items={companySettings}
                    value={section}
                    label={t('Company configuration')}
                    onChange={(next) => {
                        setSection(next);
                        const url = new URL(location.href);
                        url.searchParams.set('section', next);
                        history.replaceState(history.state, '', url);
                    }}
                />
                <PlatformAccountTable
                    key={JSON.stringify(filters)}
                    page={records}
                    companies={companies}
                    filters={filters}
                    extraQuery={{ section }}
                    url="/platform/company-configurations"
                    searchLabel={t('Search name, slug or domain')}
                    statuses={['DRAFT', 'ACTIVE', 'SUSPENDED', 'CLOSED']}
                    columns={[
                        {
                            label: 'Company',
                            className: 'min-w-56 max-w-72',
                            render: (c) => (
                                <div>
                                    <strong className="block truncate" title={c.name}>
                                        {c.name}
                                    </strong>
                                    <p className="text-xs text-muted-foreground">{c.slug}</p>
                                </div>
                            ),
                        },
                        { label: 'Status', render: (c) => t(c.status) },
                        { label: 'Locales', render: (c) => c.default_locale },
                        { label: 'Timezone', render: (c) => c.timezone },
                        {
                            label: 'Actions',
                            render: (c) => (
                                <div className="flex items-center gap-2">
                                    <Button asChild size="sm">
                                        <Link href={companySettingsUrl(c.id, section)}>
                                            {t('Edit')}
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost">
                                        <Link
                                            href={`/platform/tenants/${c.id}/configuration/card-products`}
                                        >
                                            {t('Card products')}
                                        </Link>
                                    </Button>
                                    <Button asChild size="sm" variant="ghost">
                                        <Link href={`/platform/tenants/${c.id}/configuration/team`}>
                                            {t('Team')}
                                        </Link>
                                    </Button>
                                </div>
                            ),
                        },
                    ]}
                />
            </div>
        </PlatformLayout>
    );
}
