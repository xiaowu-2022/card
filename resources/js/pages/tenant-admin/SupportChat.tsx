import { Head, Link } from '@inertiajs/react';
import { t, useAdminTranslation } from '@/i18n/admin';
import { TenantAdminLayout } from '@/layouts/TenantAdminLayout';
import { PageHeader } from '@/components/shared/PageHeader';
import { Button } from '@/components/ui/button';
import { SupportThread, type SupportChat } from '@/components/support/SupportThread';
import '../../../css/support.css';

export default function SupportChatPage({ chat }: { chat: SupportChat }) {
    useAdminTranslation();
    return (
        <TenantAdminLayout>
            <Head title={t('Support messages')} />
            <div className="mx-auto max-w-4xl">
                <PageHeader
                    title={t('Support messages')}
                    description={`${t('Account ID')}: ${chat.accountId ?? ''}`}
                    actions={
                        <Button variant="secondary" asChild>
                            <Link href="/admin/support">{t('Back to inbox')}</Link>
                        </Button>
                    }
                />
                <SupportThread key={chat.id} chat={chat} admin t={t} />
            </div>
        </TenantAdminLayout>
    );
}
