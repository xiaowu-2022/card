<?php

namespace App\Application\Card;

/** Read projection only. U1 source: https://doc.u1card.com/#trade-type */
final class CardTransactionType
{
    public static function display(string $raw): string
    {
        return match (strtoupper(trim($raw))) {
            'AUTHORIZATION' => 'authorization',
            'REFUND' => 'refund',
            'FUND COLLECTION' => 'fund_collection',
            'ATM DEPOSIT' => 'atm_deposit',
            'REVERSAL' => 'reversal',
            'VALIDATION' => 'verification',
            'SETTLEMENT DEBIT' => 'settlement_debit',
            'SETTLEMENT CREDIT' => 'settlement_credit',
            'SETTLEMENT REVERSAL' => 'settlement_reversal',
            'CHARGEBACK DEBIT' => 'chargeback_debit',
            'CHARGEBACK CREDIT' => 'chargeback_credit',
            default => in_array($raw, ['purchase', 'authorization', 'verification', 'reversal', 'refund', 'transfer_in', 'transfer_out', 'fee', 'cash_withdrawal', 'balance_inquiry', 'adjustment', 'other', 'fund_collection', 'atm_deposit', 'settlement_debit', 'settlement_credit', 'settlement_reversal', 'chargeback_debit', 'chargeback_credit'], true) ? $raw : 'other',
        };
    }
}
