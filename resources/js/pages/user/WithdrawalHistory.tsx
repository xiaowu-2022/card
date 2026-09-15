import { Head } from '@inertiajs/react';
import { t, useClientTranslation } from '@/i18n';
import { UserLayout } from '@/layouts/UserLayout';
import { UserPageHeader } from '@/components/user/UserPageHeader';
import {
    WithdrawalHistory as HistoryList,
    type WithdrawalHistoryData,
} from '@/components/user/WithdrawalHistory';

export default function WithdrawalHistory({ history }: { history: WithdrawalHistoryData }) {
    useClientTranslation();
    return (
        <UserLayout>
            <Head title={t('Withdrawal history')} />
            <div className="space-y-6 sm:space-y-8">
                <UserPageHeader title={t('Withdrawal history')} backHref="/wallet/withdraw" />
                <HistoryList history={history} />
            </div>
        </UserLayout>
    );
}
