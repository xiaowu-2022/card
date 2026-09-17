import type { PaidPromotionData } from './PaidPromotionSummary';
import { t } from '@/i18n';
import { exactAmount } from '@/lib/exact-amount';
import { promotionUnits } from '@/lib/paid-promotion';

export function AnnualRebateProgress({
    progress: p,
    preview = false,
}: {
    progress: NonNullable<PaidPromotionData['progress']>;
    preview?: boolean;
}) {
    const units = p.direct * 2 + p.indirect;
    const target = p.target * 2;
    const remaining = Math.max(0, target - units) / 2;
    const returned = promotionUnits(p.remaining) === 0n;
    return (
        <div className="space-y-2 border-t border-current/20 pt-3 text-xs leading-5">
            <div className="flex flex-wrap items-center justify-between gap-x-2">
                <span>
                    {t(
                        preview
                            ? 'Annual fee return progress after payment'
                            : 'Annual fee return progress',
                    )}
                </span>
                <span className="tabular-nums">
                    {units / 2} / {p.target}
                </span>
            </div>
            <div
                role="progressbar"
                aria-label={t('Annual fee return progress')}
                aria-valuemin={0}
                aria-valuemax={p.target}
                aria-valuenow={Math.min(units / 2, p.target)}
                className="h-1 overflow-hidden rounded-full bg-current/15"
            >
                <div
                    className="h-full rounded-full bg-current"
                    style={{ width: `${Math.min(100, (units / target) * 100)}%` }}
                />
            </div>
            <p role="status" className="break-words font-medium">
                {preview && remaining === 0
                    ? t(
                          'After successful payment, {{amount}} USDT will be returned automatically.',
                          {
                              amount: exactAmount(p.remaining),
                          },
                      )
                    : p.pending
                      ? t('Annual fee return is processing.')
                      : returned
                        ? t('Annual fee returned: {{amount}} USDT', {
                              amount: exactAmount(p.returned),
                          })
                        : remaining > 0
                          ? t(
                                '{{count}} more weighted account activations to automatically return {{amount}} USDT',
                                {
                                    count: remaining,
                                    amount: exactAmount(p.remaining),
                                },
                            )
                          : t('Annual fee return is processing.')}
            </p>
        </div>
    );
}
