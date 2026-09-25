import { Head, router } from '@inertiajs/react';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { PartnerStockReport, type StockReport } from '@/components/user/PartnerStockReport';
import { t, useClientTranslation } from '@/i18n';
import '../../../css/partner-stock.css';
export default function PartnerStock({ report }: { report: StockReport }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Stock data')} />
            <div className="stock-page">
                <UserPageHeader title={t('Stock data')} backHref="/promotion/daily" />
                <PartnerStockReport
                    report={report}
                    onPage={(page) =>
                        router.get('/promotion/stock', { page }, { preserveScroll: true })
                    }
                />
            </div>
        </UserLayout>
    );
}
