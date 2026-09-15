import { t } from '@/i18n';
import { displayMoney } from '@/lib/exact-amount';

export function WithdrawalAmounts({ fee, receive }: { fee: string; receive: string | null }) {
    return (
        <dl className="space-y-3 text-sm">
            <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">{t('Withdrawal fee')}</dt>
                <dd className="tabular-nums">{displayMoney(fee)} USDT</dd>
            </div>
            <div className="flex justify-between gap-4">
                <dt className="text-muted-foreground">{t('Amount to receive')}</dt>
                <dd className="font-semibold tabular-nums">
                    {receive === null ? '—' : displayMoney(receive)} USDT
                </dd>
            </div>
        </dl>
    );
}
