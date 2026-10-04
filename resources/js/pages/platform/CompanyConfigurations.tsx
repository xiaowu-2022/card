import { Head, Link } from '@inertiajs/react';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { PlatformAccountTable, type AccountPage } from '@/components/shared/PlatformAccountTable';
import { RenameCompany } from '@/components/admin/RenameCompany';
import { CompanyLifecycleControls } from '@/components/admin/CompanyLifecycleControls';
import { Button } from '@/components/ui/button';
import { t } from '@/i18n/admin';
import { useState } from 'react';
type Company = {
    id: string;
    name: string;
    slug: string;
    status: string;
    default_locale: string;
    timezone: string;
};
const sections = [
    ['assets', 'Asset settings'],
    ['settings/branding', 'Branding'],
    ['settings/locales', 'Locales'],
    ['settings/business', 'Business rules'],
    ['settings/articles', 'About us articles'],
    ['settings/sms', 'Aliyun SMS'],
    ['settings/email', 'Proton email'],
    ['promotion', 'Promotion'],
    ['wealth', 'Wealth settings'],
];
export default function CompanyConfigurations({
    records,
    companies,
    filters,
}: {
    records: AccountPage<Company>;
    companies: { id: string; name: string }[];
    filters: Record<string, string>;
}) {
    const [section, setSection] = useState(
        () => new URL(location.href).searchParams.get('section') ?? 'settings/branding',
    );
    return (
        <PlatformLayout>
            <Head title={t('Company configuration')} />
            <div className="space-y-5">
                <PageHeader title={t('Company configuration')} />
                <label className="flex max-w-lg items-center gap-3">
                    {t('Settings')}
                    <select
                        className="min-h-10 flex-1 rounded border bg-surface p-2"
                        value={section}
                        onChange={(e) => {
                            setSection(e.target.value);
                            const url = new URL(location.href);
                            url.searchParams.set('section', e.target.value);
                            history.replaceState(history.state, '', url);
                        }}
                    >
                        {sections.map(([path, label]) => (
                            <option key={path} value={path}>
                                {t(label!)}
                            </option>
                        ))}
                    </select>
                </label>
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
                            render: (c) => (
                                <div>
                                    <strong>{c.name}</strong>
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
                                <div className="flex flex-wrap gap-2">
                                    <RenameCompany company={c} />
                                    <Button asChild size="sm">
                                        <Link
                                            href={
                                                section === 'assets'
                                                    ? `/platform/settings/assets?company=${c.id}`
                                                    : `/platform/tenants/${c.id}/configuration/${sections.some(([p]) => p === section) ? section : 'settings/branding'}`
                                            }
                                        >
                                            {t('Edit')}
                                        </Link>
                                    </Button>
                                    <CompanyLifecycleControls company={c} />
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
