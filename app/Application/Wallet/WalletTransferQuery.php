<?php

namespace App\Application\Wallet;

use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\WalletTransfer;

final readonly class WalletTransferQuery
{
    public function __construct(private UserWalletQuery $wallets) {}

    public function get(string $tenantId, string $userId, ?string $transferId = null): array
    {
        $wallet = $this->wallets->get($tenantId, $userId);
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $receipt = null;
        if ($transferId !== null) {
            $transfer = WalletTransfer::query()->where('tenant_id', $tenantId)->whereKey($transferId)
                ->where(fn ($q) => $q->where('sender_user_id', $userId)->orWhere('recipient_user_id', $userId))->firstOrFail();
            $receipt = ['id' => $transfer->id, 'requestId' => $transfer->request_id,
                'amount' => $transfer->amount, 'asset' => $transfer->asset_code, 'sent' => $transfer->sender_user_id === $userId,
                'recipientAccountId' => $transfer->recipient_account_id,
                'senderAccountId' => User::query()->where('tenant_id', $tenantId)->whereKey($transfer->sender_user_id)->value('account_id'),
                'createdAt' => $transfer->created_at->toIso8601String()];
        }

        return ['accountId' => $user->account_id, 'available' => $wallet['eligibility']['available'],
            'transferAvailable' => $wallet['transferAvailable'], 'receipt' => $receipt];
    }
}
