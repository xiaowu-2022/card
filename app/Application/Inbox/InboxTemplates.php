<?php

namespace App\Application\Inbox;

final class InboxTemplates
{
    public const KEYS = ['deposit', 'transfer_received', 'transfer_sent', 'withdrawal_success', 'withdrawal_rejected',
        'kyc_approved', 'kyc_rejected', 'card_issue_success', 'card_issue_failed', 'card_load_success', 'card_load_failed',
        'card_return_success', 'card_return_failed', 'card_cancel_success', 'card_cancel_failed', 'card_activation_success', 'card_activation_failed',
        'deposit_funded', 'deposit_refunded', 'wealth_deposit', 'wealth_interest', 'wealth_mature', 'wealth_renew', 'wealth_return',
        'membership', 'commission_activation', 'commission_annual', 'rebate'];
}
