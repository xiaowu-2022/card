<?php

namespace App\Application\Assets;

final class AssetActivityLabel
{
    /** Labels describe the posted business event, not an inferred final order result. */
    public static function for(string $event, string $amount): string
    {
        if ($event === 'WALLET_TRANSFER') {
            return str_starts_with($amount, '-') ? 'Transfer sent' : 'Transfer received';
        }

        return match ($event) {
            'WALLET_TOPUP_CREDIT' => 'Wallet top up',
            'SECURITY_DEPOSIT_FUND' => 'Security deposit',
            'WITHDRAWAL_HOLD' => 'Withdrawal requested',
            'WITHDRAWAL_RELEASE' => 'Withdrawal returned',
            'WITHDRAWAL_SETTLE' => 'Withdrawal completed',
            'CARD_ISSUE_FEE_HOLD' => 'Card opening fee reserved',
            'CARD_INITIAL_LOAD_HOLD' => 'Initial card funding reserved',
            'CARD_ISSUE_FEE_RELEASE' => 'Card opening fee returned',
            'CARD_INITIAL_LOAD_RELEASE' => 'Initial card funding returned',
            'CARD_ISSUE_FEE_SETTLE' => 'Card opening completed',
            'CARD_INITIAL_LOAD_SETTLE' => 'Initial card funding completed',
            'CARD_LOAD_HOLD' => 'Card reload reserved',
            'CARD_LOAD_SETTLE' => 'Card reload completed',
            'CARD_LOAD_RELEASE' => 'Card reload returned',
            'CARD_RETURN_SETTLE' => 'Card balance returned',
            'CARD_CANCEL_RETURN_SETTLE' => 'Card balance returned',
            'PROMOTION_ANNUAL_FEE' => 'Promotion annual fee paid',
            'PROMOTION_FEE_REBATE' => 'Annual fee returned',
            'COMMISSION_TRANSFER' => 'Balance transfer received',
            'COMMISSION_EARN' => 'Activation commission',
            'PROMOTION_ANNUAL_COMMISSION' => 'Annual fee commission',
            'COMMISSION_BALANCE_CONSOLIDATED' => 'Commission credited to USDT',
            'SECURITY_DEPOSIT_REFUND' => 'Security deposit refunded',
            'WEALTH_DEPOSIT' => 'Wealth deposit debit',
            'WEALTH_INTEREST' => 'Wealth interest received',
            'WEALTH_MATURITY' => 'Wealth maturity principal returned',
            'WEALTH_CANCEL' => 'Wealth early withdrawal returned',
            'ASSET_DEPOSIT' => 'Wallet top up',
            'ASSET_EXCHANGE_IN' => 'Exchange received',
            'ASSET_EXCHANGE_OUT' => 'Exchange paid',
            'ASSET_WITHDRAWAL_HOLD' => 'Withdrawal requested',
            'ASSET_WITHDRAWAL_RELEASE' => 'Withdrawal returned',
            'ASSET_WITHDRAWAL_SETTLE' => 'Withdrawal completed',
            default => 'Other wallet activity',
        };
    }
}
