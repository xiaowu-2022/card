import { Head, Link } from '@inertiajs/react';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { UserLayout } from '@/layouts/UserLayout';
import { t, dateTime, useClientTranslation } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
export default function AssetHistory({
    asset,
    rows,
}: {
    asset: string;
    rows: {
        data: { id: string; amount: string; time: string; kind: string }[];
        next_page_url: string | null;
        prev_page_url: string | null;
    };
}) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Account activity')} />
            <div className="mb-6">
                <UserPageHeader
                    title={`${t('Account activity')} · ${asset}`}
                    backHref="/dashboard"
                />
            </div>
            {rows.data.length === 0 && (
                <p className="py-8 text-center text-muted-foreground">{t('No activity yet')}</p>
            )}
            {rows.data.map((r) => (
                <div
                    key={r.id}
                    className="flex items-center justify-between gap-3 border-b py-4 text-sm"
                >
                    <div>
                        <p>{t(r.kind)}</p>
                        <p className="mt-1 text-xs text-muted-foreground">{dateTime(r.time)}</p>
                    </div>
                    <p className="max-w-[55%] break-all text-right font-medium">
                        {exactAmount(r.amount)} {asset}
                    </p>
                </div>
            ))}
            <div className="mt-5 flex justify-between">
                {rows.prev_page_url && (
                    <Link className="min-h-11 py-3" href={rows.prev_page_url}>
                        {t('Previous')}
                    </Link>
                )}
                {rows.next_page_url && (
                    <Link className="min-h-11 py-3" href={rows.next_page_url}>
                        {t('Next')}
                    </Link>
                )}
            </div>
        </UserLayout>
    );
}
