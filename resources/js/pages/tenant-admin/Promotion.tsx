import { useState } from 'react';
import { Input } from '@/components/ui/input';
import { Button } from '@/components/ui/button';
import { Head, Link, router } from '@inertiajs/react';
import { CompanyConfigurationLayout } from '@/components/admin/CompanyConfiguration';
import { useCompanyConfigurationUrl } from '@/hooks/useCompanyConfigurationUrl';
import { t, useAdminTranslation } from '@/i18n/admin';
export default function Promotion({
    promotion: p,
}: {
    promotion: {
        companyCode: string;
        accountId: string;
        members: { accountId: string; code: string | null; levelId: string | null }[];
        page: number;
        hasMore: boolean;
    };
}) {
    useAdminTranslation();
    const url = useCompanyConfigurationUrl();
    const [search, setSearch] = useState(p.accountId);
    const paidUrl = url('/admin/paid-promotion');
    return (
        <CompanyConfigurationLayout>
            <Head title={t('Promotion')} />
            <div className="space-y-5">
                <h1 className="text-xl font-semibold">{t('Promotion')}</h1>
                <p>
                    {t('Company invitation code')}: {p.companyCode}
                </p>
                <p className="text-sm text-muted-foreground">
                    {t(
                        'Rules apply to new payments only. Qualification requires payment; manual level assignment is unavailable.',
                    )}
                </p>
                {paidUrl.startsWith('/platform/') && (
                    <Link className="inline-flex rounded-lg border px-4 py-3" href={paidUrl}>
                        {t('Paid promotion settings')}
                    </Link>
                )}
                <form
                    className="flex gap-3"
                    onSubmit={(e) => {
                        e.preventDefault();
                        router.get(url('/admin/promotion'), { account_id: search, page: 1 });
                    }}
                >
                    <Input
                        aria-label={t('Search account ID')}
                        placeholder={t('Search account ID')}
                        value={search}
                        maxLength={12}
                        onChange={(e) => setSearch(e.target.value.replace(/[^0-9]/g, ''))}
                    />
                    <Button>{t('Search')}</Button>
                </form>
                <div className="overflow-x-auto">
                    <table className="w-full text-left text-sm">
                        <thead>
                            <tr>
                                <th className="p-3">{t('Account ID')}</th>
                                <th className="p-3">{t('Invitation code')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {p.members.map((m) => (
                                <tr key={m.accountId} className="border-t">
                                    <td className="p-3">{m.accountId}</td>
                                    <td className="p-3">{m.code ?? '—'}</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="flex gap-4">
                    {p.page > 1 && (
                        <button
                            onClick={() =>
                                router.get(url('/admin/promotion'), {
                                    page: p.page - 1,
                                    account_id: p.accountId,
                                })
                            }
                        >
                            {t('Previous')}
                        </button>
                    )}
                    {p.hasMore && (
                        <button
                            onClick={() =>
                                router.get(url('/admin/promotion'), {
                                    page: p.page + 1,
                                    account_id: p.accountId,
                                })
                            }
                        >
                            {t('Next')}
                        </button>
                    )}
                </div>
            </div>
        </CompanyConfigurationLayout>
    );
}
