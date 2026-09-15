<?php

namespace App\Application\Wallet;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wallet\Models\WalletTransfer;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class TransferWalletBalanceAction
{
    public function __construct(private LedgerWriter $ledger, private KycStatusService $kyc, private AuditLogger $audit) {}

    public function execute(string $tenantId, string $senderId, string $recipientAccountId, string $amount, string $requestId): WalletTransfer
    {
        if (! Str::isUuid($requestId) || ! preg_match('/^[0-9]{12}$/D', $recipientAccountId)
            || ! preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $amount)) {
            throw new DomainException('WALLET_TRANSFER_INVALID', 'Enter a valid account ID and an amount with up to two decimal places.');
        }

        return DB::transaction(function () use ($tenantId, $senderId, $recipientAccountId, $amount, $requestId): WalletTransfer {
            // Tenant -> Users -> Wallets -> LedgerWriter accounts. No external work.
            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $money = Money::of($amount, $tenant->default_asset);
            if (! $money->isPositive()) {
                throw new DomainException('WALLET_TRANSFER_INVALID', 'Enter a positive transfer amount.');
            }
            $existing = WalletTransfer::query()->where('tenant_id', $tenantId)->where('sender_user_id', $senderId)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->recipient_account_id !== $recipientAccountId || $existing->amount !== $money->amount() || $existing->asset_code !== $money->assetCode) {
                    throw new DomainException('WALLET_TRANSFER_CONFLICT', 'This transfer request was already used with different details.', 409);
                }

                return $existing;
            }
            $recipient = User::query()->where('tenant_id', $tenantId)->where('account_id', $recipientAccountId)->first();
            if (! $recipient || $recipient->id === $senderId) {
                throw new DomainException('WALLET_TRANSFER_RECIPIENT_UNAVAILABLE', 'The recipient is unavailable. Check the account ID and company.');
            }
            $users = User::query()->where('tenant_id', $tenantId)->whereIn('id', [$senderId, $recipient->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $wallets = Wallet::query()->where('tenant_id', $tenantId)->whereIn('user_id', [$senderId, $recipient->id])
                ->where('asset_code', $money->assetCode)->orderBy('id')->lockForUpdate()->get()->keyBy('user_id');
            foreach ([$senderId, $recipient->id] as $id) {
                if ($tenant->status !== TenantStatus::Active || $users->get($id)?->status !== UserStatus::Active
                    || $wallets->get($id)?->status !== WalletStatus::Active || $this->kyc->forUser($tenantId, $id) !== KycUserStatus::Approved) {
                    throw new DomainException('WALLET_TRANSFER_UNAVAILABLE', 'Both accounts need active verified wallets in the same currency.');
                }
            }
            $senderWallet = $wallets[$senderId];
            $recipientWallet = $wallets[$recipient->id];
            $accounts = LedgerAccount::query()->where('tenant_id', $tenantId)->where('asset_code', $money->assetCode)
                ->whereIn('wallet_id', [$senderWallet->id, $recipientWallet->id])->where('account_type', 'USER_AVAILABLE')->get()->keyBy('user_id');
            if ($accounts->count() !== 2) {
                throw new DomainException('WALLET_TRANSFER_UNAVAILABLE', 'Both accounts need active verified wallets in the same currency.');
            }
            $id = (string) Str::uuid();
            try {
                $entry = $this->ledger->post(new LedgerPostingPlan($tenantId, $money->assetCode, 'wallet_transfer:'.$id, 'WALLET_TRANSFER', 'WALLET_TRANSFER', $id, null, [
                    new LedgerPostingInstruction($accounts[$senderId]->id, Money::of('-'.$money->amount(), $money->assetCode)),
                    new LedgerPostingInstruction($accounts[$recipient->id]->id, $money),
                ]));
            } catch (DomainException $error) {
                if ($error->errorCode === 'LEDGER_NEGATIVE_BALANCE') {
                    throw new DomainException('WALLET_TRANSFER_INSUFFICIENT', 'Your available balance is insufficient for this transfer.');
                }
                throw new DomainException('WALLET_TRANSFER_UNAVAILABLE', 'The transfer could not be completed safely. Please try again with the same request.', 409);
            }
            $transfer = WalletTransfer::query()->create(['id' => $id, 'tenant_id' => $tenantId, 'sender_user_id' => $senderId,
                'recipient_user_id' => $recipient->id, 'sender_wallet_id' => $senderWallet->id, 'recipient_wallet_id' => $recipientWallet->id,
                'recipient_account_id' => $recipientAccountId, 'amount' => $money->amount(), 'asset_code' => $money->assetCode,
                'request_id' => $requestId, 'ledger_entry_id' => $entry->id]);
            $this->audit->record($tenantId, 'USER', $senderId, 'WALLET_TRANSFER_COMPLETED', 'wallet_transfer', $id, null,
                ['amount' => $money->amount(), 'asset' => $money->assetCode, 'recipient_user_id' => $recipient->id]);

            return $transfer->refresh();
        }, 3);
    }
}
