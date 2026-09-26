<?php

namespace App\Application\Wallet;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransfer;

final readonly class WalletTransferQuery
{
    public function __construct(private UserWalletQuery $wallets) {}

    public function get(string $tenantId, string $userId, ?string $transferId = null): array
    {
        $wallet = $this->wallets->get($tenantId, $userId);
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $wallets = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->get()->keyBy('asset_code');
        $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->where('account_type', 'USER_AVAILABLE')->where('status', 'ACTIVE')->get()->keyBy('asset_code');
        $eligible = $wallet['eligibility']['tenantStatus'] === 'ACTIVE'
            && $wallet['eligibility']['userStatus'] === 'ACTIVE' && $wallet['eligibility']['kycStatus'] === 'APPROVED';
        $assets = array_map(function (string $asset) use ($wallets, $accounts, $eligible): array {
            $account = $accounts->get($asset);

            return ['asset' => $asset, 'scale' => Money::scale($asset),
                'amount' => Money::of($account?->balance ?? '0', $asset)->amount(),
                'available' => $eligible && $wallets->get($asset)?->status->value === 'ACTIVE'
                    && $account !== null && $account->wallet_id === $wallets->get($asset)?->id];
        }, AssetCatalog::ASSETS);
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
            'assets' => $assets, 'transferAvailable' => $eligible, 'receipt' => $receipt];
    }
}
