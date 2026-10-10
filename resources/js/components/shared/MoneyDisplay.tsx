import type { MoneyAmount } from '@/types/global';
import { displayMoney } from '@/lib/exact-amount';
import { displayMoney as adminAmount } from '@/lib/admin-amount';

const symbols: Record<string, string> = { USD: '$', EUR: '€', GBP: '£', MYR: 'RM ' };

export function MoneyDisplay({
    amount,
    asset,
    assetLabel = asset,
    hideSymbol = false,
    exact = false,
}: {
    amount: MoneyAmount;
    asset: string;
    assetLabel?: string;
    compact?: boolean;
    hideSymbol?: boolean;
    exact?: boolean;
}) {
    const [integer = '0', fraction = ''] = (
        exact ? adminAmount(amount) : displayMoney(amount)
    ).split('.');
    const sign = integer.startsWith('-') ? '-' : '';
    const digits = sign ? integer.slice(1) : integer;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const rendered = `${sign}${grouped}${fraction ? `.${fraction}` : ''}`;
    return (
        <span className="tabular-nums">
            {!hideSymbol && (symbols[asset] ?? '')}
            {rendered}
            {!hideSymbol && !symbols[asset] && ` ${assetLabel}`}
        </span>
    );
}
