import { t, useClientTranslation } from '@/i18n';
import { ArrowDown, ArrowUp, ArrowRightLeft, ShieldCheck } from 'lucide-react';
import { UserQuickActions } from './UserQuickActions';

export function UserWalletActions({
    unavailableHref = '/support',
    topupAvailable,
    withdrawalAvailable,
    depositAvailable,
    transferAvailable = false,
}: {
    unavailableHref?: string;
    topupAvailable: boolean;
    withdrawalAvailable: boolean;
    depositAvailable: boolean;
    transferAvailable?: boolean;
}) {
    useClientTranslation();
    return (
        <UserQuickActions
            actions={[
                {
                    label: t('Top up'),
                    unavailable: !topupAvailable,
                    href: topupAvailable ? '/wallet/top-up' : unavailableHref,
                    icon: ArrowDown,
                    unavailableLabel: t('View requirements'),
                },
                {
                    label: t('Withdraw'),
                    unavailable: !withdrawalAvailable,
                    href: withdrawalAvailable ? '/wallet/withdraw' : unavailableHref,
                    icon: ArrowUp,
                    unavailableLabel: t('View requirements'),
                },
                {
                    label: t('Security deposit'),
                    unavailable: !depositAvailable,
                    href: depositAvailable ? '/security-deposit' : unavailableHref,
                    icon: ShieldCheck,
                    unavailableLabel: t('View requirements'),
                },
                {
                    label: t('Transfer'),
                    unavailable: !transferAvailable,
                    href: transferAvailable ? '/wallet/transfer' : unavailableHref,
                    icon: ArrowRightLeft,
                    unavailableLabel: t('View requirements'),
                },
            ]}
        />
    );
}
