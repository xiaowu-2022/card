<?php

namespace App\Application\Assets;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Assets\AssetDepositOrder;
use App\Domain\Assets\ChainObservation;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

final readonly class DepositAssetsAction
{
    public function __construct(private AssetAccess $access, private AssetRails $rails, private LedgerWriter $ledger, private AuditLogger $audit) {}

    public function create(string $tenantId, string $userId, string $railCode, string $amount, string $requestId): AssetDepositOrder
    {
        AssetAccess::requestId($requestId);

        return DB::transaction(function () use ($tenantId, $userId, $railCode, $amount, $requestId) {
            AssetAccess::lock('asset-deposit:'.$tenantId.':'.$userId.':'.$requestId);
            $existing = AssetDepositOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($existing) {
                if ($existing->rail_code !== $railCode || ! BigDecimal::of($existing->requested_amount)->isEqualTo($amount)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with different details.', 409);
                }

                return $existing;
            }
            [$tenant,$user] = $this->access->operational($tenantId, $userId);
            [$rail,$company] = $this->rails->enabled($tenantId, $railCode, 'deposit');
            $money = $this->rails->amount($amount, $rail->asset_code);
            if (BigDecimal::of($amount)->isLessThan($company->minimum_deposit)) {
                throw new DomainException('AMOUNT_INVALID', 'The amount is below the minimum deposit.');
            }
            $address = $rail->deposit_address;
            $hash = hash('sha256', $address);
            AssetAccess::lock('asset-slot:'.$railCode.':'.$hash);
            $wallet = $this->access->wallet($tenant, $user, $rail->asset_code);
            $stablecoin = in_array($rail->asset_code, ['USDT', 'USDC'], true);
            if ($stablecoin && ! BigDecimal::of($money->amount())->isEqualTo(BigDecimal::of($money->amount())->toScale(2, RoundingMode::Down))) {
                throw new DomainException('AMOUNT_INVALID', 'Enter a positive amount with at most 2 decimal places.');
            }
            // Database padding is not configured precision: 0.010000 means two places.
            $fraction = explode('.', (string) $company->minimum_deposit, 2)[1] ?? '';
            $offsetScale = $stablecoin ? 2 : strlen(rtrim($fraction, '0')) + 2;
            if ($offsetScale > AssetCatalog::chainScale($rail->asset_code)) {
                throw new DomainException('AMOUNT_INVALID', 'Minimum deposit precision leaves no room for two identification digits.');
            }
            $unit = BigDecimal::of('1')->withPointMovedLeft($offsetScale);
            $exact = BigDecimal::of($money->amount())->plus($unit);
            $found = false;
            for ($i = 0; $i < 99; $i++) {
                if (! AssetDepositOrder::query()->where('rail_code', $railCode)->where('address_hash', $hash)->where('amount', (string) $exact)->exists()) {
                    $found = true;
                    break;
                }
                $exact = $exact->plus($unit);
            }
            if (! $found) {
                throw new DomainException('ASSET_SLOTS_UNAVAILABLE', 'Try a different deposit amount.', 409);
            }

            return AssetDepositOrder::query()->create(['tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $wallet->id, 'asset_code' => $rail->asset_code, 'rail_code' => $railCode, 'network' => $rail->network, 'contract' => $rail->contract, 'address' => $address, 'address_hash' => $hash, 'request_id' => $requestId, 'request_hash' => hash('sha256', $railCode.':'.$money->amount()), 'amount' => Money::of((string) $exact, $rail->asset_code)->amount(), 'requested_amount' => $money->amount(), 'expires_at' => now()->addMinutes(30)]);
        }, 3);
    }

    public function manual(string $tenantId, string $id, AdminUser $actor, string $requestId, bool $confirmed): AssetDepositOrder
    {
        $this->access->platform($actor, 'wallet_topups.confirm');
        AssetAccess::requestId($requestId);
        if (! $confirmed) {
            throw new DomainException('CONFIRMATION_REQUIRED', 'Explicit confirmation is required.');
        }

        return DB::transaction(function () use ($tenantId, $id, $actor, $requestId) {
            AssetAccess::lock('asset-manual:'.$actor->id.':'.$requestId);
            $order = AssetDepositOrder::query()->where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'CREDITED') {
                return $order;
            }
            if (! in_array($order->status, ['PENDING', 'CONFIRMING'], true) || $order->expires_at->lessThanOrEqualTo(now())) {
                throw new DomainException('TOPUP_CONFIRMATION_UNAVAILABLE', 'This deposit is not eligible for manual confirmation.', 409);
            }
            if (AssetDepositOrder::query()->where('manual_confirmed_by', $actor->id)->where('manual_request_id', $requestId)->exists()) {
                throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used.', 409);
            }

            return $this->credit($order, null, $actor, $requestId);
        }, 3);
    }

    /** Called only with persisted, uniquely matched, finalized observations; never request-body proof. */
    public function verified(ChainObservation $observation): void
    {
        DB::transaction(function () use ($observation): void {
            $observation = ChainObservation::query()->whereKey($observation->id)->lockForUpdate()->firstOrFail();
            if ($observation->status !== 'MATCHED' || ! $observation->order_id) {
                return;
            }
            $order = AssetDepositOrder::query()->whereKey($observation->order_id)->where('network', $observation->network)->where('rail_code', $observation->rail_code)->where('address', $observation->address)->where('amount', $observation->amount)->lockForUpdate()->firstOrFail();
            if ($order->status === 'CREDITED') {
                $observation->update(['status' => 'ALREADY_CREDITED']);

                return;
            }
            if (! in_array($order->status, ['PENDING', 'CONFIRMING'], true)) {
                $observation->update(['status' => 'REQUIRES_REVIEW']);

                return;
            }
            $this->credit($order, $observation->event_id);
            $observation->update(['status' => 'CREDITED']);
        }, 3);
    }

    private function credit(AssetDepositOrder $order, ?string $event, ?AdminUser $actor = null, ?string $request = null): AssetDepositOrder
    {
        // Settled incoming funds remain payable after account suspension.
        $this->access->settlementOwners($order->tenant_id, $order->user_id);
        $wallet = Wallet::query()->where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->where('asset_code', $order->asset_code)->whereKey($order->wallet_id)->firstOrFail();
        $available = $this->access->account($wallet, 'USER_AVAILABLE');
        $clearing = $this->access->companyAccount($order->tenant_id, $order->asset_code, 'TENANT_TOPUP_CLEARING');
        $entry = $this->ledger->post(new LedgerPostingPlan($order->tenant_id, $order->asset_code, 'asset_deposit:'.$order->id.':credit', 'ASSET_DEPOSIT', 'ASSET_DEPOSIT', $order->id, null, [new LedgerPostingInstruction($clearing->id, Money::of('-'.$order->amount, $order->asset_code)), new LedgerPostingInstruction($available->id, Money::of($order->amount, $order->asset_code))]));
        $changes = ['status' => 'CREDITED', 'chain_event_id' => $event, 'ledger_entry_id' => $entry->id];
        if ($actor) {
            $changes += ['manual_confirmed_by' => $actor->id, 'manual_confirmed_at' => now(), 'manual_request_id' => $request];
        }
        $order->update($changes);
        $this->audit->record($order->tenant_id, $actor ? 'ADMIN' : 'SYSTEM', $actor?->id, $actor ? 'ASSET_DEPOSIT_MANUALLY_CONFIRMED' : 'ASSET_DEPOSIT_CREDITED', 'asset_deposit_order', $order->id, null, ['amount' => $order->amount, 'asset' => $order->asset_code, 'chain_verified' => $event !== null], $request);

        return $order->refresh();
    }
}
