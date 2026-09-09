import type { MoneyAmount } from '@/types/global';

const symbols: Record<string, string> = { USD: '$', EUR: '€', GBP: '£', MYR: 'RM ' };

export function MoneyDisplay({
    amount,
    asset,
    compact = false,
    hideSymbol = false,
}: {
    amount: MoneyAmount;
    asset: string;
    compact?: boolean;
    hideSymbol?: boolean;
}) {
    const [integer = '0', fraction = ''] = amount.split('.');
    const sign = integer.startsWith('-') ? '-' : '';
    const digits = sign ? integer.slice(1) : integer;
    const grouped = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    const precision = compact
        ? fraction.slice(0, 2).padEnd(2, '0')
        : fraction.padEnd(8, '0').slice(0, 8);
    const rendered = `${sign}${grouped}.${precision}`;
    return (
        <span className="tabular-nums">
            {!hideSymbol && (symbols[asset] ?? '')}
            {rendered}
            {!hideSymbol && !symbols[asset] && ` ${asset}`}
        </span>
    );
}
