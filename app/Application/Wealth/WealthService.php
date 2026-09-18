<?php

namespace App\Application\Wealth;

use App\Application\Assets\AssetAccess;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\DTOs\LedgerPostingInstruction;
use App\Domain\Ledger\DTOs\LedgerPostingPlan;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\Services\LedgerWriter;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Wealth\WealthMath;
use App\Domain\Wealth\WealthOrder;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class WealthService
{
    public function deposit(string $tenantId, string $userId, string $asset, string $amount, int $months, string $revision, string $requestId): WealthOrder
    {
        AssetAccess::requestId($requestId);
        AssetCatalog::assert($asset);
        try {
            $money = Money::of($amount, $asset);
        } catch (\Throwable) {
            throw new DomainException('WEALTH_AMOUNT_INVALID', 'Enter a positive amount with valid currency precision.');
        }
        if (! $money->isPositive() || ! isset(WealthMath::RATES[$months])) {
            throw new DomainException('WEALTH_AMOUNT_INVALID', 'Enter a positive amount with valid currency precision.');
        }

        return DB::transaction(function () use ($tenantId, $userId, $asset, $money, $months, $revision, $requestId) {
            [$tenant, $user] = app(AssetAccess::class)->settlementOwners($tenantId, $userId);
            $old = WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $requestId)->first();
            if ($old) {
                if ($old->asset_code !== $asset || ! BigDecimal::of($old->principal)->isEqualTo($money->amount()) || $old->months !== $months || $old->config_revision !== $revision) {
                    throw new DomainException('WEALTH_REQUEST_CONFLICT', 'This wealth request was already used with different details.', 409);
                }

                return $old;
            }
            $wallet = Wallet::where('tenant_id', $tenantId)->where('user_id', $userId)->where('asset_code', $asset)->lockForUpdate()->first();
            if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE' || $wallet?->status->value !== 'ACTIVE' || app(KycStatusService::class)->forUser($tenantId, $userId)->value !== 'APPROVED') {
                throw new DomainException('WEALTH_UNAVAILABLE', 'An active verified wallet is required.', 403);
            }
            $setting = collect(app(WealthConfiguration::class)->get($tenantId))->firstWhere('asset', $asset);
            $product = collect($setting['products'])->firstWhere('months', $months);
            if ($setting['revision'] !== $revision || ! $product['enabled'] || $setting['minimum'] === '' || BigDecimal::of($money->amount())->isLessThan($setting['minimum'])) {
                throw new DomainException('WEALTH_PRODUCT_CHANGED', 'Wealth settings changed. Reload and review.', 409);
            }
            $id = (string) Str::uuid();
            $start = CarbonImmutable::now('UTC')->startOfSecond();
            $schedule = WealthMath::schedule($money->amount(), $asset, $product['rate'], $months, $start, $tenant->timezone);
            $entry = $this->post($wallet, $id, 'deposit', 'WEALTH_DEPOSIT', ['USER_AVAILABLE' => '-'.$money->amount(), 'USER_WEALTH_PRINCIPAL' => $money->amount()]);
            $order = WealthOrder::create(['id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $wallet->id, 'asset_code' => $asset, 'principal' => $money->amount(), 'months' => $months, 'annual_rate' => $product['rate'], 'config_revision' => $revision, 'timezone' => $tenant->timezone, 'schedule' => $schedule, 'started_at' => $start, 'matures_at' => $schedule[$months - 1]['due_at'], 'request_id' => $requestId, 'status' => 'ACTIVE', 'deposit_entry_id' => $entry]);
            foreach ($schedule as $row) {
                DB::table('wealth_installments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'order_id' => $id, 'month' => $row['month'], 'due_at' => $row['due_at'], 'amount' => $row['amount']]);
            }
            $this->audit($order, 'USER', 'WEALTH_DEPOSIT', $entry);

            return $order;
        }, 3);
    }

    public function paid(WealthOrder $order): string
    {
        return Money::of((string) DB::table('wealth_installments')->where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->whereNotNull('settled_at')->sum('amount'), $order->asset_code)->amount();
    }

    public function cancel(string $tenantId, string $userId, string $id, string $requestId, string $password, string $expectedPaid): WealthOrder
    {
        AssetAccess::requestId($requestId);

        return DB::transaction(function () use ($tenantId, $userId, $id, $requestId, $password, $expectedPaid) {
            [$tenant, $user] = app(AssetAccess::class)->settlementOwners($tenantId, $userId);
            $order = WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if (! Hash::check($password, $user->password_hash)) {
                throw new DomainException('WEALTH_PASSWORD_INVALID', 'The current password is incorrect.');
            }
            if ($order->status === 'CANCELLED' && $order->cancel_request_id === $requestId) {
                if (! BigDecimal::of($order->clawback)->isEqualTo($expectedPaid)) {
                    throw new DomainException('WEALTH_REQUEST_CONFLICT', 'This wealth request was already used with different details.', 409);
                }

                return $order;
            }
            if (WealthOrder::where('tenant_id', $tenantId)->where('user_id', $userId)->where('cancel_request_id', $requestId)->where('id', '!=', $id)->exists()) {
                throw new DomainException('WEALTH_REQUEST_CONFLICT', 'This wealth request was already used with different details.', 409);
            }
            if ($order->status !== 'ACTIVE') {
                throw new DomainException('WEALTH_CLOSED', 'This wealth deposit is already closed.', 409);
            }
            if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE') {
                throw new DomainException('WEALTH_UNAVAILABLE', 'An active verified wallet is required.', 403);
            }
            $wallet = $this->wallet($order);
            if (now()->greaterThanOrEqualTo($order->matures_at)) {
                return $this->settleLocked($order, $wallet);
            }
            $paid = $this->paid($order);
            if (! BigDecimal::of($paid)->isEqualTo($expectedPaid)) {
                throw new DomainException('WEALTH_PREVIEW_CHANGED', 'Interest changed. Review the cancellation amount again.', 409);
            }
            $returned = (string) BigDecimal::of($order->principal)->minus($paid);
            $entry = $this->post($wallet, $id, 'close', 'WEALTH_CANCEL', ['USER_WEALTH_PRINCIPAL' => '-'.$order->principal, 'USER_AVAILABLE' => $returned, 'TENANT_WEALTH_INTEREST_CLEARING' => $paid]);
            $order->update(['status' => 'CANCELLED', 'closed_at' => now()->startOfSecond(), 'cancel_request_id' => $requestId, 'returned' => $returned, 'clawback' => $paid, 'close_entry_id' => $entry]);
            $this->audit($order, 'USER', 'WEALTH_CANCEL', $entry);

            return $order;
        }, 3);
    }

    public function settle(string $tenantId, string $id): WealthOrder
    {
        $read = WealthOrder::where('tenant_id', $tenantId)->whereKey($id)->firstOrFail();

        return DB::transaction(function () use ($tenantId, $id, $read) {
            app(AssetAccess::class)->settlementOwners($tenantId, $read->user_id);
            $order = WealthOrder::where('tenant_id', $tenantId)->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($order->status !== 'ACTIVE') {
                return $order;
            }

            return $this->settleLocked($order, $this->wallet($order));
        }, 3);
    }

    private function settleLocked(WealthOrder $order, Wallet $wallet): WealthOrder
    {
        $due = DB::table('wealth_installments')->where('tenant_id', $order->tenant_id)->where('order_id', $order->id)->whereNull('settled_at')->where('due_at', '<=', now())->orderBy('month')->get();
        foreach ($due as $row) {
            $entry = BigDecimal::of($row->amount)->isZero() ? null : $this->post($wallet, $order->id, 'interest:'.$row->month, 'WEALTH_INTEREST', ['TENANT_WEALTH_INTEREST_CLEARING' => '-'.$row->amount, 'USER_AVAILABLE' => $row->amount]);
            DB::table('wealth_installments')->where('tenant_id', $order->tenant_id)->where('id', $row->id)->update(['settled_at' => now(), 'ledger_entry_id' => $entry]);
            if ($entry) {
                $this->audit($order, 'SYSTEM', 'WEALTH_INTEREST', $entry);
            }
        }
        if (now()->greaterThanOrEqualTo($order->matures_at)) {
            $entry = $this->post($wallet, $order->id, 'close', 'WEALTH_MATURITY', ['USER_WEALTH_PRINCIPAL' => '-'.$order->principal, 'USER_AVAILABLE' => $order->principal]);
            $order->update(['status' => 'MATURED', 'closed_at' => now()->startOfSecond(), 'returned' => $order->principal, 'clawback' => '0', 'close_entry_id' => $entry]);
            $this->audit($order, 'SYSTEM', 'WEALTH_MATURITY', $entry);
        }

        return $order;
    }

    private function wallet(WealthOrder $order): Wallet
    {
        $wallet = Wallet::where('tenant_id', $order->tenant_id)->where('user_id', $order->user_id)->where('asset_code', $order->asset_code)->whereKey($order->wallet_id)->lockForUpdate()->firstOrFail();
        if ($wallet->status->value !== 'ACTIVE') {
            throw new DomainException('WEALTH_WALLET_UNAVAILABLE', 'The receiving wallet is unavailable. Settlement will retry.', 409);
        }

        return $wallet;
    }

    private function post(Wallet $wallet, string $id, string $suffix, string $type, array $amounts): string
    {
        $instructions = [];
        foreach ($amounts as $accountType => $amount) {
            if (BigDecimal::of($amount)->isZero()) {
                continue;
            }
            $company = $accountType === 'TENANT_WEALTH_INTEREST_CLEARING';
            $identity = ['tenant_id' => $wallet->tenant_id, 'asset_code' => $wallet->asset_code, 'account_type' => $accountType, 'wallet_id' => $company ? null : $wallet->id, 'user_id' => $company ? null : $wallet->user_id];
            if ($accountType !== 'USER_AVAILABLE') {
                DB::table('ledger_accounts')->insertOrIgnore($identity + ['id' => (string) Str::uuid(), 'balance' => '0', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            }
            $account = LedgerAccount::where($identity)->firstOrFail();
            $instructions[] = new LedgerPostingInstruction($account->id, Money::of($amount, $wallet->asset_code));
        }

        try {
            return app(LedgerWriter::class)->post(new LedgerPostingPlan($wallet->tenant_id, $wallet->asset_code, 'wealth:'.$id.':'.$suffix, $type, 'WEALTH_ORDER', $id, null, $instructions))->id;
        } catch (DomainException $error) {
            if ($type === 'WEALTH_DEPOSIT' && $error->errorCode === 'LEDGER_NEGATIVE_BALANCE') {
                throw new DomainException('WEALTH_INSUFFICIENT', 'Your available balance is insufficient for this wealth deposit.');
            }
            throw new DomainException('WEALTH_SETTLEMENT_UNAVAILABLE', 'This wealth operation could not be completed safely. Please try again.', 409);
        }
    }

    private function audit(WealthOrder $order, string $actor, string $action, string $entry): void
    {
        app(AuditLogger::class)->record($order->tenant_id, $actor, $actor === 'USER' ? $order->user_id : null, $action, 'wealth_order', $order->id, null, ['entry_id' => $entry, 'asset' => $order->asset_code]);
    }
}
