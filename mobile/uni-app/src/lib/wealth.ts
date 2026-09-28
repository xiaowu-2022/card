import { t, locale } from './i18n';
export type WealthSetting = {
    asset: string;
    minimum: string;
    revision: string | null;
    products: { months: number; rate: string; enabled: boolean }[];
    available: string;
    principal: string;
    interest: string;
    net: string;
};
export type WealthOrder = {
    id: string;
    asset: string;
    principal: string;
    rate: string;
    months: number;
    status: string;
    displayStatus: string;
    startedAt: string;
    maturesAt: string;
    paid: string;
    returnAmount: string;
    clawback: string | null;
    closedAt: string | null;
    canCancel: boolean;
    canRedeem: boolean;
    maturityPolicy: 'AUTO_RETURN' | 'MANUAL_REDEEM_RENEW';
    redeemBefore: string | null;
    redeemBeforeLocal: string | null;
    previousOrderId: string | null;
    nextOrderId: string | null;
    timezone: string;
};
export function wealthState(state: string) {
    return t(
        (
            {
                REDEEMABLE: 'Available for redemption',
                RENEWAL_PENDING: 'Renewal pending',
                REDEEMED: 'Redeemed to wallet',
                RENEWED: 'Renewed for next term',
                AWAITING_SETTLEMENT: 'Matured awaiting settlement',
                ACTIVE: 'Earning interest',
                MATURED: 'Matured and returned',
                CANCELLED: 'Cancelled early',
            } as Record<string, string>
        )[state] ?? state,
    );
}
export function wealthDate(value: string, timezone: string) {
    return new Intl.DateTimeFormat(locale.value, {
        timeZone: timezone,
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value));
}
export function wealthTerm(months: number, rate: string) {
    return t('{{months}} months · {{rate}}% annual rate', { months, rate });
}
