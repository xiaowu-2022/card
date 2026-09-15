import { Head } from '@inertiajs/react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import { SupportThread, type SupportChat } from '@/components/support/SupportThread';
import '../../../css/support.css';

export default function Support({ chat }: { chat: SupportChat }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Customer support')} />
            <UserPageHeader title={t('Customer support')} backHref="/account" />
            <SupportThread chat={chat} t={t} />
        </UserLayout>
    );
}
