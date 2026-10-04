<?php

namespace App\Application\Assets;

use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\WalletTransfer;
use Illuminate\Support\Collection;

/** Consumer-safe context only; never expose internal adjustment reasons or staff identities. */
final class AssetActivityDetails
{
    public function forRows(string $tenantId, string $userId, Collection $rows): Collection
    {
        $transfers = WalletTransfer::where('tenant_id', $tenantId)
            ->whereIn('id', $rows->where('event_type', 'WALLET_TRANSFER')->pluck('reference_id')->filter())
            ->where(fn ($q) => $q->where('sender_user_id', $userId)->orWhere('recipient_user_id', $userId))
            ->get()->keyBy('id');
        $users = User::where('tenant_id', $tenantId)->whereIn('id', $transfers->pluck('sender_user_id')->merge($transfers->pluck('recipient_user_id')))
            ->get(['id', 'email', 'account_id'])->keyBy('id');

        return $rows->mapWithKeys(function ($row) use ($transfers, $users, $userId) {
            $details = ['reason' => self::reason($row->event_type, $row->delta), 'reference' => $row->reference_id,
                'counterparty' => null];
            $transfer = $row->event_type === 'WALLET_TRANSFER' ? $transfers->get($row->reference_id) : null;
            if ($transfer && $row->reference_type === 'WALLET_TRANSFER' && $transfer->ledger_entry_id === $row->entry_id) {
                $sent = $transfer->sender_user_id === $userId;
                $other = $users->get($sent ? $transfer->recipient_user_id : $transfer->sender_user_id);
                $details['counterparty'] = ['role' => $sent ? 'recipient' : 'sender',
                    'accountId' => $sent ? $transfer->recipient_account_id : $other?->account_id,
                    'email' => $other?->email];
            }

            return [$row->id => $details];
        });
    }

    private static function reason(string $event, string $amount): string
    {
        if ($event === 'WALLET_TRANSFER') {
            return str_starts_with($amount, '-') ? 'Funds transferred to the recipient.' : 'Funds received from the sender.';
        }

        return match ($event) {
            'WALLET_TOPUP_CREDIT', 'ASSET_DEPOSIT' => 'Confirmed deposit credited to the wallet.',
            'SECURITY_DEPOSIT_FUND' => 'Wallet funds used to pay the security deposit.',
            'SECURITY_DEPOSIT_REFUND' => 'Security deposit returned to the wallet.',
            'PROMOTION_ANNUAL_FEE' => 'Wallet funds used to pay the promotion annual fee.',
            'PROMOTION_FEE_REBATE' => 'Annual fee rebate credited to the wallet.',
            'COMMISSION_EARN' => 'Activation commission credited to the wallet.',
            'PROMOTION_ANNUAL_COMMISSION' => 'Annual fee commission credited to the wallet.',
            'WEALTH_DEPOSIT' => 'Wallet funds used for a wealth deposit.',
            'WEALTH_INTEREST' => 'Wealth interest credited to the wallet.',
            'WEALTH_MATURITY' => 'Matured wealth principal returned to the wallet.',
            'WEALTH_CANCEL' => 'Wealth principal returned after early withdrawal deductions.',
            'ASSET_EXCHANGE_IN' => 'Destination currency received from an exchange.',
            'ASSET_EXCHANGE_OUT' => 'Source currency paid for an exchange.',
            'CARD_ISSUE_FEE_HOLD', 'CARD_INITIAL_LOAD_HOLD' => 'Funds reserved for card opening and initial funding.',
            'CARD_ISSUE_FEE_SETTLE' => 'Payment of the card opening fee.',
            'CARD_INITIAL_LOAD_SETTLE' => 'Initial funds added to the card.',
            'CARD_ISSUE_FEE_RELEASE', 'CARD_INITIAL_LOAD_RELEASE' => 'Reserved card opening funds returned to the wallet.',
            'CARD_LOAD_HOLD' => 'Funds reserved for a card reload.',
            'CARD_LOAD_SETTLE' => 'Payment for a card reload.',
            'CARD_LOAD_RELEASE' => 'Reserved card reload funds returned to the wallet.',
            'CARD_RETURN_SETTLE', 'CARD_CANCEL_RETURN_SETTLE' => 'Card funds returned to the wallet.',
            'WITHDRAWAL_HOLD', 'ASSET_WITHDRAWAL_HOLD' => 'Funds reserved for a withdrawal.',
            'WITHDRAWAL_SETTLE', 'ASSET_WITHDRAWAL_SETTLE' => 'Reserved funds used to complete a withdrawal.',
            'WITHDRAWAL_RELEASE', 'ASSET_WITHDRAWAL_RELEASE' => 'Reserved withdrawal funds returned to the wallet.',
            'MANUAL_COMMISSION' => str_starts_with($amount, '-') ? 'Manual commission deducted from the wallet.' : 'Manual commission credited to the wallet.',
            'WALLET_ADJUSTMENT' => str_starts_with($amount, '-') ? 'Platform balance deduction.' : 'Platform balance credit.',
            default => AssetActivityLabel::for($event, $amount),
        };
    }
}
