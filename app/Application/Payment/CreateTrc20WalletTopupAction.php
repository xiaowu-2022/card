<?php

namespace App\Application\Payment;

use App\Application\Payment\DTOs\CreatedWalletTopup;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Payment\Enums\PaymentProviderTransactionStatus;
use App\Domain\Payment\Enums\WalletTopupStatus;
use App\Domain\Payment\Models\WalletTopupOrder;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Domain\Wallet\Enums\WalletStatus;
use App\Domain\Wallet\Models\Wallet;
use App\Domain\Withdrawal\Contracts\BlockchainGatewayInterface;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class CreateTrc20WalletTopupAction
{
    private const RAIL = 'TRC20_SHARED';

    private const PROVIDER = 'trc20-shared';

    /** @var list<string> */
    private const RESERVED_STATUSES = ['PENDING', 'PROCESSING', 'PAID'];

    public function __construct(
        private BlockchainGatewayInterface $gateway,
        private KycStatusService $kycStatus,
        private AuditLogger $audit,
    ) {}

    public function execute(string $tenantId, string $userId, mixed $requestedAmount, string $requestId, ?string $auditRequestId = null): CreatedWalletTopup
    {
        if (! is_string($requestedAmount) || preg_match('/^(?:0|[1-9]\d*)(?:\.\d{1,2})?$/', $requestedAmount) !== 1) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Enter a valid amount with at most 2 decimal places.');
        }
        if (! Str::isUuid($requestId)) {
            throw new DomainException('TOPUP_REQUEST_ID_INVALID', 'A valid request identifier is required.');
        }
        try {
            $requested = Money::of($requestedAmount, 'USDT');
        } catch (InvalidArgumentException) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Enter a valid amount with at most 2 decimal places.');
        }
        if (! $requested->isPositive()) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Top-up amount must be greater than zero.');
        }
        try {
            $requested->add(Money::of('0.99', 'USDT'));
        } catch (InvalidArgumentException) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Top-up amount exceeds the supported limit.');
        }
        $requestHash = $this->requestHash($tenantId, $userId, $requested->amount());
        $existing = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
        if ($existing) {
            if (! hash_equals($existing->request_hash, $requestHash)) {
                throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with a different amount.', 409);
            }

            return new CreatedWalletTopup($existing, null, false);
        }
        if (! $this->gateway->available()) {
            throw new DomainException('BLOCKCHAIN_MONITOR_UNAVAILABLE', 'USDT top-up is currently unavailable.', 503);
        }
        [$depositAddress, $tokenContract] = $this->configuredRail();
        $created = false;

        /** @var WalletTopupOrder $order */
        $order = DB::transaction(function () use ($tenantId, $userId, $requested, $requestId, $requestHash, $depositAddress, $tokenContract, $auditRequestId, &$created): WalletTopupOrder {
            DB::statement('SELECT pg_advisory_xact_lock(?)', [$this->allocationLockKey($depositAddress)]);
            $existing = WalletTopupOrder::query()->where('tenant_id', $tenantId)->where('request_id', $requestId)->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $requestHash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request identifier was already used with a different amount.', 409);
                }

                return $existing;
            }

            $tenant = Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            $wallet = Wallet::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            $tenant->loadMissing('businessSettings');
            if ($tenant->status !== TenantStatus::Active) {
                throw new DomainException('TENANT_NOT_ACTIVE', 'Top-up is unavailable while this tenant is not active.', 403);
            }
            if ($user->status !== UserStatus::Active) {
                throw new DomainException('USER_NOT_ACTIVE', 'Your account must be active to start a top-up.', 403);
            }
            if ($this->kycStatus->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Identity verification must be approved before topping up.', 403);
            }
            if (! $wallet || $wallet->status !== WalletStatus::Active) {
                throw new DomainException('WALLET_NOT_ACTIVE', 'An active wallet is required.', 403);
            }
            if (! $tenant->businessSettings->allow_wallet_topup) {
                throw new DomainException('WALLET_TOPUP_DISABLED', 'Top-up is not enabled for this tenant.', 403);
            }
            if ($wallet->asset_code !== 'USDT') {
                throw new DomainException('TOPUP_ASSET_MISMATCH', 'V1 blockchain top-up requires a USDT wallet.', 409);
            }

            $allocation = $this->allocate($depositAddress, $requested);
            if ($allocation === null) {
                throw new DomainException('TOPUP_AMOUNT_SLOTS_EXHAUSTED', 'All identification amounts for this top-up are in use. Try again later.', 409);
            }
            [$increment, $expected] = $allocation;
            $id = (string) Str::uuid();
            $now = now();
            $validity = max(1, (int) config('payment.trc20_validity_minutes'));
            DB::table('wallet_topup_orders')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'user_id' => $userId, 'wallet_id' => $wallet->id,
                'request_id' => $requestId, 'request_hash' => $requestHash, 'asset_code' => 'USDT',
                'amount' => $expected->amount(), 'status' => WalletTopupStatus::Pending->value,
                'payment_provider' => self::PROVIDER, 'payment_rail' => self::RAIL,
                'requested_amount' => $requested->amount(), 'expected_amount' => $expected->amount(),
                'identification_increment' => $increment, 'network_code' => 'TRON',
                'deposit_address' => $depositAddress, 'token_contract' => $tokenContract,
                'expires_at' => $now->copy()->addMinutes($validity), 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('payment_provider_transactions')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'wallet_topup_order_id' => $id,
                'provider' => self::PROVIDER, 'provider_request_id' => $id,
                'status' => PaymentProviderTransactionStatus::Pending->value, 'asset_code' => 'USDT',
                'amount' => $expected->amount(), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->audit->record($tenantId, 'USER', $userId, 'TRC20_TOPUP_CREATED', 'wallet_topup_order', $id, null, [
                'asset' => 'USDT', 'network' => 'TRON', 'status' => WalletTopupStatus::Pending->value,
            ], $auditRequestId);
            $created = true;

            return WalletTopupOrder::query()->findOrFail($id);
        }, 3);

        return new CreatedWalletTopup($order, null, $created);
    }

    /** @return array{string, Money}|null */
    private function allocate(string $depositAddress, Money $requested): ?array
    {
        $reserved = DB::table('wallet_topup_orders')->where('payment_rail', self::RAIL)
            ->where('deposit_address', $depositAddress)->whereIn('status', self::RESERVED_STATUSES)
            ->pluck('expected_amount')->mapWithKeys(fn (string $amount): array => [$amount => true]);
        $history = DB::table('wallet_topup_orders')->where('payment_rail', self::RAIL)
            ->where('deposit_address', $depositAddress)->where('requested_amount', $requested->amount())
            ->selectRaw('identification_increment, COUNT(*) AS usage_count, MAX(created_at) AS last_used_at')
            ->groupBy('identification_increment')->get()->keyBy('identification_increment');
        $candidates = [];
        for ($slot = 1; $slot <= 99; $slot++) {
            $increment = '0.'.str_pad((string) $slot, 2, '0', STR_PAD_LEFT).'000000';
            $expected = $requested->add(Money::of($increment, 'USDT'));
            if ($reserved->has($expected->amount())) {
                continue;
            }
            $use = $history->get($increment);
            $candidates[] = [
                'increment' => $increment,
                'expected' => $expected,
                'count' => (int) ($use->usage_count ?? 0),
                'last' => $use->last_used_at ?? '',
                'slot' => $slot,
            ];
        }
        usort($candidates, static fn (array $left, array $right): int => [$left['count'], $left['last'], $left['slot']] <=> [$right['count'], $right['last'], $right['slot']]);
        if ($candidates === []) {
            return null;
        }

        return [$candidates[0]['increment'], $candidates[0]['expected']];
    }

    /** @return array{string,string} */
    private function configuredRail(): array
    {
        $address = (string) config('payment.trc20_deposit_address');
        $token = (string) config('payment.trc20_token_contract');
        $pattern = '/^T[1-9A-HJ-NP-Za-km-z]{33}$/';
        if (preg_match($pattern, $address) !== 1 || preg_match($pattern, $token) !== 1) {
            throw new DomainException('TRC20_TOPUP_CONFIGURATION_INVALID', 'USDT top-up is currently unavailable.', 503);
        }

        return [$address, $token];
    }

    private function requestHash(string $tenantId, string $userId, string $requestedAmount): string
    {
        $parts = ['trc20-shared-topup-v1', $tenantId, $userId, $requestedAmount, 'USDT', 'TRON'];

        return hash('sha256', implode('', array_map(static fn (string $part): string => pack('N', strlen($part)).$part, $parts)));
    }

    private function allocationLockKey(string $depositAddress): int
    {
        /** @var array{high:int,low:int} $words */
        $words = unpack('Nhigh/Nlow', substr(hash('sha256', "trc20-topup-allocation-v1\0{$depositAddress}", true), 0, 8));

        return ($words['high'] << 32) | $words['low'];
    }
}
