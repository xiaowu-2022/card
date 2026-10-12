export const userRestrictionLabels = {
    withdrawal_blocked: 'Block withdrawals',
    deposit_refund_blocked: 'Block deposit refund requests',
    card_transfer_blocked: 'Block card transfers',
    wallet_transfer_blocked: 'Block account transfers',
} as const;

export type OperationRestrictions = Record<keyof typeof userRestrictionLabels, boolean>;
