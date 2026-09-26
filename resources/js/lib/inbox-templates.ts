export const inboxTemplates: Record<string, readonly [string, string]> = {
    deposit: [
        'Deposit credited',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    transfer_received: [
        'Transfer received',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    transfer_sent: [
        'Transfer completed',
        'Your transfer of {{amount}} {{asset}} has completed successfully.',
    ],
    withdrawal_success: [
        'Withdrawal completed',
        'Your withdrawal has been verified as completed. The net payout is {{amount}} {{asset}}.',
    ],
    withdrawal_rejected: [
        'Withdrawal rejected',
        'Your withdrawal was rejected. The reserved {{amount}} {{asset}} has been released to your available balance.',
    ],
    kyc_approved: [
        'Identity verification approved',
        'Your identity verification has been approved.',
    ],
    kyc_rejected: [
        'Identity verification rejected',
        'Your identity verification was not approved. Open identity verification to review the result and next steps.',
    ],
    card_issue_success: [
        'Card opened successfully',
        'The card operation has been confirmed. Open your cards to view the result.',
    ],
    card_issue_failed: [
        'Card opening failed',
        'The card operation has failed definitively. Check the related record or contact customer support.',
    ],
    card_load_success: [
        'Card funding completed',
        'Your card funding of {{amount}} {{asset}} has completed successfully.',
    ],
    card_load_failed: [
        'Card funding failed',
        'The card operation has failed definitively. Check the related record or contact customer support.',
    ],
    card_return_success: [
        'Card funds returned',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    card_return_failed: [
        'Card funds return failed',
        'The card operation has failed definitively. Check the related record or contact customer support.',
    ],
    card_cancel_success: [
        'Card cancelled',
        'The card operation has been confirmed. Open your cards to view the result.',
    ],
    card_cancel_failed: [
        'Card cancellation failed',
        'The card operation has failed definitively. Check the related record or contact customer support.',
    ],
    card_activation_success: [
        'Physical card activated',
        'The card operation has been confirmed. Open your cards to view the result.',
    ],
    card_activation_failed: [
        'Physical card activation failed',
        'The card operation has failed definitively. Check the related record or contact customer support.',
    ],
    deposit_funded: [
        'Security deposit funded',
        'Your payment of {{amount}} {{asset}} has completed successfully. View the related record for details.',
    ],
    deposit_refunded: [
        'Security deposit returned',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    wealth_deposit: [
        'Wealth deposit completed',
        'Your payment of {{amount}} {{asset}} has completed successfully. View the related record for details.',
    ],
    wealth_interest: [
        'Wealth interest credited',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    wealth_mature: [
        'Wealth principal is ready for redemption',
        'Your {{amount}} {{asset}} wealth principal has matured. Open the order to check the redemption deadline; unredeemed principal renews automatically.',
    ],
    wealth_renew: [
        'Wealth principal renewed',
        'Your {{amount}} {{asset}} principal has renewed at the original term and rate. Paid interest remains in your wallet.',
    ],
    wealth_return: [
        'Wealth principal returned',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    membership: [
        'Agent membership payment completed',
        'Your membership payment of {{amount}} {{asset}} has completed. Open the promotion center to view your active level and expiry.',
    ],
    commission_activation: [
        'Activation commission credited',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    commission_annual: [
        'Annual-fee commission credited',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
    rebate: [
        'Annual fee returned',
        '{{amount}} {{asset}} has been credited to your available balance. View the related record for details.',
    ],
};
