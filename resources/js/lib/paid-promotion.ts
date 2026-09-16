import { t } from '@/i18n';
import { displayMoney } from '@/lib/exact-amount';
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

// Display-only exact USDT arithmetic; all payment amounts still come from server quotes.
export function promotionUnits(amount: string): bigint {
    const [whole = '0', fraction = ''] = amount.split('.');
    return BigInt(whole) * 100000000n + BigInt(fraction.padEnd(8, '0').slice(0, 8));
}
export function commissionSum(...amounts: string[]): string {
    const total = amounts.reduce((sum, amount) => sum + promotionUnits(amount), 0n);
    return `${total / 100000000n}.${(total % 100000000n).toString().padStart(8, '0')}`;
}
export function promotionMoney(amount: string): string {
    const [whole = '0', fraction = ''] = amount.split('.');
    const decimals = fraction.replace(/0+$/, '').padEnd(2, '0');
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${decimals} USDT`;
}
export function membershipAction(p: { rank: number; membershipStatus: string }): string {
    if (p.rank >= 8) return 'View level benefits';
    if (p.membershipStatus === 'EXPIRED') return 'Renew promotion membership';
    return p.rank > 0 ? 'Upgrade promotion level' : 'Apply for promotion membership';
}

// Compact, display-only table values; expanded details retain original precision.
export function promotionTableAmount(amount: string): string {
    const units = promotionUnits(amount);
    if (units > 0n && units < 1000000n) return '<0.01';
    const [whole = '0', fraction = '00'] = displayMoney(amount).split('.');
    return `${whole.replace(/\B(?=(\d{3})+(?!\d))/g, ',')}.${fraction}`;
}
