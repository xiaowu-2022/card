import { t } from '@/i18n';
export function promotionLevel(rank: number): string {
    return rank ? t('Mastercard level {{rank}}', { rank }) : t('Ordinary member');
}
export function rebateStatus(status: string): string {
    return t(
        (
            {
                PENDING: 'Under review',
                APPROVED: 'Fee returned',
                REJECTED: 'Request rejected',
                WITHDRAWN: 'Request withdrawn',
            } as Record<string, string>
        )[status] ?? 'Under review',
    );
}
