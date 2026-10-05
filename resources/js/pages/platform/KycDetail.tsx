import { Head, Link } from '@inertiajs/react';
import { useAdminTranslation, t } from '@/i18n/admin';
import { PlatformLayout } from '@/layouts/PlatformLayout';
import { KycDetailsContent, type KycApplication } from '@/components/admin/KycDetailsContent';

export default function KycDetail(props: {
    application: KycApplication;
    company: { id: string; name: string };
    canViewDocuments: boolean;
}) {
    useAdminTranslation();
    return (
        <PlatformLayout title={t('Identity verification details')} description={props.company.name}>
            <Head title={t('Identity verification details')} />
            <Link
                className="text-primary underline"
                href={`/platform/users?company=${props.company.id}&kyc_company=${props.company.id}&kyc_user=${props.application.user.id}&kyc_application=${props.application.id}`}
            >
                {t('Back')}
            </Link>
            <KycDetailsContent {...props} />
        </PlatformLayout>
    );
}
