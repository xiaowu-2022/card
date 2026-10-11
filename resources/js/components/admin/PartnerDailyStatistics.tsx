import { MoneyDisplay } from './MoneyDisplay';
import { t } from '@/i18n/admin';
export const partnerDailyColumns = [
    ['deposits', 'Ordinary member deposit income'],
    ['annual', 'Annual fee income'],
    ['depositRefunds', 'Ordinary member deposit refunds'],
    ['activation', 'Activation commission'],
    ['rebates', 'Annual fee refunds'],
    ['annualCommission', 'Annual commission'],
] as const;
export type PartnerDaily = Record<string, Record<string, string>>;
export function PartnerDailyStatistics({
    values,
    expanded,
    onToggle,
    account,
}: {
    values?: Record<string, string>;
    expanded: boolean;
    onToggle: () => void;
    account: string;
}) {
    return (
        <button
            type="button"
            className="w-full min-w-36 rounded-md p-1 text-left hover:bg-muted focus-visible:outline-primary"
            aria-expanded={expanded}
            aria-label={t('Toggle daily statistics for {{account}}', { account })}
            onClick={onToggle}
        >
            <div className="flex items-center justify-between gap-2">
                <span className="text-xs text-muted-foreground">{t('Statistics today')}</span>
                <MoneyDisplay amount={values?.today ?? '0'} asset="USDT" hideSymbol />
            </div>
            {expanded &&
                [3, 7, 15, 30].map((days) => (
                    <div className="mt-2 flex items-center justify-between gap-3" key={days}>
                        <span className="text-xs text-muted-foreground">
                            {t('{{days}} day average', { days })}
                        </span>
                        <MoneyDisplay amount={values?.[days] ?? '0'} asset="USDT" hideSymbol />
                    </div>
                ))}
            <span className="mt-1 block text-right text-xs text-primary">
                {t(expanded ? 'Collapse' : 'Expand')}
            </span>
        </button>
    );
}
