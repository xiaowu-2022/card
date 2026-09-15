import type { MoneyAmount } from '@/types/global';
import { displayMoney } from '@/lib/exact-amount';

const symbols: Record<string, string> = { USD: '$', EUR: '€', GBP: '£', MYR: 'RM ' };

export function MoneyDisplay({
    amount,
    asset,
    hideSymbol = false,
}: {
    amount: MoneyAmount;
    asset: string;
    compact?: boolean;
    hideSymbol?: boolean;
}) {
    const [integer = '0', fraction = '00'] = displayMoney(amount).split('.');
    const sign = integer.startsWith('-') ? '-' : '';
    const digits = sign ? integer.slice(1) : integer;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const rendered = `${sign}${grouped}.${fraction}`;
    return (
        <span className="tabular-nums">
            {!hideSymbol && (symbols[asset] ?? '')}
            {rendered}
            {!hideSymbol && !symbols[asset] && ` ${asset}`}
        </span>
    );
}
