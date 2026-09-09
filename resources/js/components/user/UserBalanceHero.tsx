import { MoneyDisplay } from '@/components/shared/MoneyDisplay';
import type { MoneyAmount } from '@/types/global';

export function UserBalanceHero({ amount, asset }: { amount: MoneyAmount; asset: string }) {
    return (
        <section
            className="overflow-hidden rounded-[var(--user-radius-lg)] border bg-surface px-5 py-7 shadow-[0_12px_40px_rgba(23,32,28,0.06)] sm:px-8 sm:py-9"
            aria-labelledby="available-balance-title"
        >
            <p id="available-balance-title" className="text-sm font-medium text-muted-foreground">
                Available balance
            </p>
            <p className="mt-3 min-w-0 break-all text-[2.5rem] leading-none font-semibold tracking-[-0.04em] sm:text-5xl">
                <MoneyDisplay amount={amount} asset={asset} compact hideSymbol />
            </p>
            <p className="mt-3 text-sm font-semibold tracking-wide text-muted-foreground">
                {asset}
            </p>
        </section>
    );
}
