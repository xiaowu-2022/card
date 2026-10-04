<?php

namespace App\Application\Wallet;

use App\Domain\Assets\AssetCatalog;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransfer;
use App\Support\Errors\DomainException;

final readonly class WalletTransferQuery
{
    public function __construct(private UserWalletQuery $wallets) {}

    /** Exact same-company lookup for an authenticated transfer review; never writes money. */
    public function recipient(string $tenantId, string $userId, string $accountId, string $asset): array
    {
        $unavailable = fn () => new DomainException('WALLET_TRANSFER_RECIPIENT_UNAVAILABLE', 'The recipient is unavailable. Check the account ID and company.');
        $sender = $this->get($tenantId, $userId);
        if (! collect($sender['assets'])->contains(fn ($a) => $a['asset'] === $asset && $a['available'])) {
            throw $unavailable();
        }
        $recipient = User::where('tenant_id', $tenantId)->where('account_id', $accountId)->where('id', '<>', $userId)->where('status', 'ACTIVE')->first();
        if (! $recipient || ! $recipient->email
            || app(KycStatusService::class)->forUser($tenantId, $recipient->id) !== KycUserStatus::Approved
            || ! Wallet::where('tenant_id', $tenantId)->where('user_id', $recipient->id)->where('asset_code', $asset)->where('status', 'ACTIVE')->exists()) {
            throw $unavailable();
        }

        return ['accountId' => $recipient->account_id, 'email' => $recipient->email, 'asset' => $asset];
    }

    public function history(string $tenantId, string $userId, int $page): array
    {
        $rows = WalletTransfer::query()->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->where('sender_user_id', $userId)->orWhere('recipient_user_id', $userId))
            ->orderByDesc('created_at')->orderByDesc('id')->simplePaginate(20, ['*'], 'page', $page);
        $parties = User::where('tenant_id', $tenantId)->whereIn('id', $rows->getCollection()->pluck('sender_user_id')->merge($rows->getCollection()->pluck('recipient_user_id')))->get(['id', 'account_id', 'email'])->keyBy('id');

        return ['items' => $rows->getCollection()->map(fn ($transfer) => [
            'id' => $transfer->id, 'amount' => $transfer->amount, 'asset' => $transfer->asset_code,
            'sent' => $transfer->sender_user_id === $userId,
            'senderAccountId' => $parties->get($transfer->sender_user_id)?->account_id,
            'senderEmail' => $parties->get($transfer->sender_user_id)?->email,
            'recipientEmail' => $parties->get($transfer->recipient_user_id)?->email,
            'recipientAccountId' => $transfer->recipient_account_id,
            'createdAt' => $transfer->created_at->toIso8601String(),
        ])->values()->all(), 'page' => $rows->currentPage(), 'hasMore' => $rows->hasMorePages()];
    }

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
            $parties = User::where('tenant_id', $tenantId)->whereIn('id', [$transfer->sender_user_id, $transfer->recipient_user_id])->get(['id', 'account_id', 'email'])->keyBy('id');
            $receipt = ['id' => $transfer->id, 'requestId' => $transfer->request_id,
                'amount' => $transfer->amount, 'asset' => $transfer->asset_code, 'sent' => $transfer->sender_user_id === $userId,
                'recipientAccountId' => $transfer->recipient_account_id,
                'senderAccountId' => $parties->get($transfer->sender_user_id)?->account_id,
                'senderEmail' => $parties->get($transfer->sender_user_id)?->email,
                'recipientEmail' => $parties->get($transfer->recipient_user_id)?->email,
                'createdAt' => $transfer->created_at->toIso8601String()];
        }

        return ['accountId' => $user->account_id, 'available' => $wallet['eligibility']['available'],
            'assets' => $assets, 'transferAvailable' => $eligible, 'receipt' => $receipt];
    }
}
