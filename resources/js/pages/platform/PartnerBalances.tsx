import { Head, Link, router } from '@inertiajs/react';
import { AdminPagination } from '@/components/admin/AdminPagination';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { exactAmount } from '@/lib/exact-amount';
import { t, useAdminTranslation } from '@/i18n/admin';
import '../../../css/partner-stock.css';

type Balance = { theoretical: string; actual: string; difference: string };
type Props = {
    companies: { id: string; name: string }[];
    companyId: string | null;
    parentId: string | null;
    listing: {
        items: {
            id: string;
            account_id: string;
            display_name: string | null;
            company_name: string;
            personal: Balance;
            team: Balance;
            teamPartners: number;
            hasChildren: boolean;
        }[];
        breadcrumbs: { id: string; name: string }[];
        page: number;
        pages: number;
        total: number;
    };
};

export default function PartnerBalances({ companies, companyId, parentId, listing }: Props) {
    useAdminTranslation();
    const href = (parent: string | null, page = 1) => {
        const query = new URLSearchParams({ page: String(page) });
        if (companyId) query.set('company', companyId);
        if (parent) query.set('parent', parent);
        return `/platform/partner-balances?${query}`;
    };
    return (
        <PlatformLayout title={t('Partner balances')}>
            <Head title={t('Partner balances')} />
            <div className="partner-admin partner-admin-platform space-y-4">
                <div className="partner-form partner-summary-filters">
                    <label>
                        {t('Company')}
                        <select
                            value={companyId ?? ''}
                            onChange={(event) =>
                                router.get(
                                    '/platform/partner-balances',
                                    event.target.value ? { company: event.target.value } : {},
                                )
                            }
                        >
                            <option value="">{t('All companies')}</option>
                            {companies.map((company) => (
                                <option key={company.id} value={company.id}>
                                    {company.name}
                                </option>
                            ))}
                        </select>
                    </label>
                </div>
                <p className="text-sm text-muted-foreground">
                    {t('Partner balance hierarchy basis')}
                </p>
                <nav
                    aria-label={t('Partner hierarchy')}
                    className="flex flex-wrap items-center gap-2 text-sm"
                >
                    <Link href={href(null)} className="partner-admin-action">
                        {t('Top-level partners')}
                    </Link>
                    {listing.breadcrumbs.map((crumb, index) => (
                        <span key={crumb.id} className="inline-flex items-center gap-2">
                            <span aria-hidden="true">/</span>
                            {index === listing.breadcrumbs.length - 1 ? (
                                <span aria-current="page">{crumb.name}</span>
                            ) : (
                                <Link href={href(crumb.id)} className="partner-admin-action">
                                    {crumb.name}
                                </Link>
                            )}
                        </span>
                    ))}
                </nav>
                <div className="overflow-x-auto rounded-lg border bg-background">
                    <table className="w-full text-sm">
                        <thead>
                            <tr className="border-b">
                                <th rowSpan={2} className="p-3 text-left">
                                    {t('Company')}
                                </th>
                                <th rowSpan={2} className="p-3 text-left">
                                    {t('Partners')}
                                </th>
                                <th colSpan={3} className="p-3 text-center">
                                    {t('Personal')} · USDT
                                </th>
                                <th colSpan={3} className="p-3 text-center">
                                    {t('Entire team')} · USDT
                                </th>
                                <th rowSpan={2} className="p-3">
                                    {t('Team partners')}
                                </th>
                                <th rowSpan={2} className="p-3">
                                    {t('Actions')}
                                </th>
                            </tr>
                            <tr className="border-b">
                                {['personal', 'team'].flatMap((scope) =>
                                    ['Theoretical balance', 'Actual balance', 'Difference'].map(
                                        (key) => (
                                            <th
                                                key={`${scope}-${key}`}
                                                className="p-3 text-right whitespace-nowrap"
                                            >
                                                {t(key)}
                                            </th>
                                        ),
                                    ),
                                )}
                            </tr>
                        </thead>
                        <tbody>
                            {listing.items.map((row) => (
                                <tr key={row.id} className="border-b">
                                    <td className="p-3">{row.company_name}</td>
                                    <td className="p-3">
                                        <div>{row.display_name || row.account_id}</div>
                                        <div className="text-xs text-muted-foreground">
                                            {row.account_id}
                                        </div>
                                    </td>
                                    {(['personal', 'team'] as const).flatMap((scope) =>
                                        (['theoretical', 'actual', 'difference'] as const).map(
                                            (key) => (
                                                <td
                                                    key={`${scope}-${key}`}
                                                    className="p-3 text-right whitespace-nowrap tabular-nums"
                                                >
                                                    {exactAmount(row[scope][key])}
                                                </td>
                                            ),
                                        ),
                                    )}
                                    <td className="p-3 text-center">{row.teamPartners}</td>
                                    <td className="p-3 text-center whitespace-nowrap">
                                        {row.hasChildren ? (
                                            <Link
                                                className="partner-admin-action"
                                                href={href(row.id)}
                                            >
                                                {t('Subordinate partners')}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                {t('No subordinate partners')}
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}
                            {!listing.items.length && (
                                <tr>
                                    <td
                                        colSpan={10}
                                        className="p-6 text-center text-muted-foreground"
                                    >
                                        {t('No subordinate partners')}
                                    </td>
                                </tr>
                            )}
                        </tbody>
                    </table>
                </div>
                <AdminPagination
                    page={listing.page}
                    pages={listing.pages}
                    total={listing.total}
                    href={(page) => href(parentId, page)}
                />
            </div>
        </PlatformLayout>
    );
}
