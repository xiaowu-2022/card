import { t } from '@/i18n';
// Preserve the chain-precision fee and payout shown at confirmation.
const displayMoney = (value: string) => value.replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');

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
