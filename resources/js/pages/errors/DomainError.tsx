import { t, errorMessage, useClientTranslation } from '@/i18n';
import { Head, usePage } from '@inertiajs/react';
import { ErrorState } from '@/components/shared/ErrorState';
import { PublicLayout } from '@/layouts/PublicLayout';
import { AdminAuthLayout } from '@/layouts/AdminAuthLayout';
import { errorMessage as adminErrorMessage } from '@/i18n/admin';

export default function DomainError({
    status,
    message,
    requestId,
}: {
    status: number;
    message: string;
    requestId: string;
}) {
    useClientTranslation();
    const { url } = usePage();
    const adminSurface = /^\/(admin|platform)(\/|$)/.test(url);
    const Layout = adminSurface ? AdminAuthLayout : PublicLayout;
    return (
        <Layout>
            <Head title={t('Error {{value1}}', { value1: status })} />
            <div className="mx-auto max-w-2xl px-4 py-20">
                <ErrorState
                    title={t('Request unavailable ({{value1}})', { value1: status })}
                    description={
                        (adminSurface ? adminErrorMessage(message) : errorMessage(message)) ?? ''
                    }
                />
                <p className="mt-4 text-center text-xs text-muted-foreground">
                    {t('Request ID:')}
                    {requestId}
                </p>
            </div>
        </Layout>
    );
}
