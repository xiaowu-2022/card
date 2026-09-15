import { MoneyDisplay as OriginalMoney } from '@/components/shared/MoneyDisplay';
import { systemMoney } from '@/lib/system-money';
import type { MoneyAmount } from '@/types/global';

export function MoneyDisplay(props: {
    amount: MoneyAmount;
    asset: string;
    compact?: boolean;
    hideSymbol?: boolean;
}) {
    if (props.hideSymbol || !['USDT', 'USD'].includes(props.asset))
        return <OriginalMoney {...props} />;
    return <span className="tabular-nums">{systemMoney(props.amount)}</span>;
}
