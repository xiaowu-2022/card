import type { ReactNode } from 'react';
import { RecordUserCell, type UserInfo } from '@/components/admin/UserInfoCell';
import { StatusBadge, type StatusTone } from '@/components/shared/StatusBadge';
import { displayMoney } from '@/lib/admin-amount';
import { adminAssetLabel } from '@/lib/admin-asset-label';
import { t, dateTime } from '@/i18n/admin';

export type UserCard = {
    promotionRank?: number;
    ordinaryMember?: boolean;
    directReferrer?: { accountId: string; displayName: string | null } | null;
    funded: string | null;
    consumed: string | null;
    openingFee: string | null;
    openingFeeAsset: string | null;
    userInfo?: UserInfo;
    userId?: string;
    id: string;
    tenantId: string;
    balance: string | null;
    overflowBalance: string;
    providerBalance: string | null;
    balanceLimit: string | null;
    effectiveBalanceLimit: string | null;
    currency: string;
    balanceUpdatedAt: string | null;
    lastTransactionSyncAt: string | null;
    companyName: string;
    userEmail: string;
    productName: string;
    maskedPan: string;
    formFactor: string;
    produceStatus: string | null;
    trackingNumber: string | null;
    providerStatus: string;
};
export const platformCardStatusTone = (status: string): StatusTone =>
    status === 'SUCCEEDED' || status === 'normal'
        ? 'SUCCESS'
        : status === 'FAILED'
          ? 'DANGER'
          : status === 'UNKNOWN'
            ? 'WARNING'
            : 'INFO';

export const platformCardColumns: {
    label: string;
    render: (row: UserCard) => ReactNode;
}[] = [
    {
        label: 'Company / User',
        render: (row) => <RecordUserCell row={row} />,
    },
    {
        label: 'User level',
        render: (row) =>
            row.promotionRank
                ? t('Mastercard level {{rank}}', {
                      rank: row.promotionRank,
                  })
                : t(row.ordinaryMember ? 'Ordinary member' : 'Registered member'),
    },
    {
        label: 'Direct referrer',
        render: (row) =>
            row.directReferrer ? (
                <div>
                    {row.directReferrer.displayName || '—'}
                    <div className="text-xs text-muted-foreground">
                        {row.directReferrer.accountId}
                    </div>
                </div>
            ) : (
                '—'
            ),
    },
    {
        label: 'U-card funding',
        render: (row) =>
            row.funded === null ? t('Not available') : `${displayMoney(row.funded)} USDT`,
    },
    {
        label: 'Opening fee',
        render: (row) =>
            row.openingFee === null
                ? t('Not available')
                : `${displayMoney(row.openingFee)} ${adminAssetLabel(row.openingFeeAsset ?? 'USDT')}`,
    },
    {
        label: 'Consumption amount',
        render: (row) =>
            row.consumed === null ? t('Not available') : `${displayMoney(row.consumed)} USDT`,
    },
    {
        label: 'Card wallet balance',
        render: (row) => (
            <div className="whitespace-nowrap">
                <div className="font-medium tabular-nums">
                    {row.balance === null
                        ? t('Not available')
                        : `${displayMoney(row.balance)} ${adminAssetLabel(row.currency)}`}
                </div>
                <div className="text-xs text-muted-foreground">
                    {row.balanceUpdatedAt ? dateTime(row.balanceUpdatedAt) : t('Not yet synced')}
                </div>
            </div>
        ),
    },
    {
        label: 'Product / Card',
        render: (row) => (
            <div className="max-w-52 space-y-1">
                <div className="truncate font-medium" title={row.productName}>
                    {row.productName}
                </div>
                <div className="flex items-center gap-2 whitespace-nowrap text-xs">
                    <span className="font-mono">{row.maskedPan}</span>
                    <span className="text-muted-foreground">
                        {t(row.formFactor === 'physical_card' ? 'Physical card' : 'Virtual card')}
                    </span>
                </div>
                {row.produceStatus && (
                    <span className="block text-xs">
                        {t(
                            row.produceStatus === 'produced'
                                ? 'Card produced'
                                : 'Card production pending',
                        )}
                    </span>
                )}
                {row.trackingNumber && (
                    <span className="block truncate text-xs" title={row.trackingNumber}>
                        {t('Tracking number')}: {row.trackingNumber}
                    </span>
                )}
            </div>
        ),
    },
    {
        label: 'Status',
        render: (row) => (
            <StatusBadge
                status={platformCardStatusTone(row.providerStatus)}
                label={t(row.providerStatus)}
            />
        ),
    },
];
