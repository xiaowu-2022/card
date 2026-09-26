import { t, clientI18n } from '@/i18n';

export const wealthState = (state: string) =>
    t(
        state === 'REDEEMABLE'
            ? 'Available for redemption'
            : state === 'RENEWAL_PENDING'
              ? 'Renewal pending'
              : state === 'REDEEMED'
                ? 'Redeemed to wallet'
                : state === 'RENEWED'
                  ? 'Renewed for next term'
                  : state === 'AWAITING_SETTLEMENT'
                    ? 'Matured awaiting settlement'
                    : state === 'ACTIVE'
                      ? 'Earning interest'
                      : state === 'MATURED'
                        ? 'Matured and returned'
                        : 'Cancelled early',
    );

export function wealthDate(value: string, timezone: string): string {
    return new Intl.DateTimeFormat(clientI18n.language, {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
