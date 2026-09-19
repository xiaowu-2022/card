<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantBusinessSettingsAction
{
    public function __construct(private AuditLogger $audit, private AuthorizationService $authorization) {}

    /** @param array{required_security_deposit_amount?:string,required_security_deposit_asset?:string,security_deposit_refund_wait_days?:int|null,withdrawal_fee_percent?:string} $data */
    public function execute(Tenant $tenant, array $data, AdminUser $actor, ?string $requestId = null): void
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        $depositConfig = array_key_exists('required_security_deposit_amount', $data) || array_key_exists('required_security_deposit_asset', $data);
        // Compatibility for trusted Platform configuration callers only. Company
        // settings never accept or persist deposit configuration, even via direct calls.
        if ($depositConfig || array_key_exists('security_deposit_refund_wait_days', $data)) {
            $currentActor = AdminUser::query()->findOrFail($actor->id);
            if ($currentActor->status !== AdminUserStatus::Active || ! $this->authorization->allows($currentActor, ScopeType::Platform, null, 'tenant.manage')) {
                throw new DomainException('DEPOSIT_SETTINGS_FORBIDDEN', 'Only SaaS administrators can configure company security deposits.', 403);
            }
        }
        $asset = $depositConfig ? strtoupper(trim($data['required_security_deposit_asset'] ?? $tenant->default_asset)) : null;
        if ($depositConfig && (! isset($data['required_security_deposit_amount']) || ! is_string($data['required_security_deposit_amount']) || ! preg_match('/\A\d{1,12}(?:\.\d{1,8})?\z/', $data['required_security_deposit_amount']))) {
            throw new DomainException('DEPOSIT_SETTINGS_INVALID', 'Enter a valid deposit amount and a waiting period from 0 to 3650 days.');
        }
        $amount = $depositConfig ? Money::of($data['required_security_deposit_amount'], $asset) : null;
        if ($amount !== null && ! $amount->isZero() && ! $amount->isPositive()) {
            throw new DomainException('NEGATIVE_DEPOSIT_REQUIREMENT', 'Security deposit requirement cannot be negative.');
        }
        $waitDays = $data['security_deposit_refund_wait_days'] ?? null;
        if (array_key_exists('security_deposit_refund_wait_days', $data) && $waitDays !== null
            && (! is_int($waitDays) || $waitDays < 0 || $waitDays > 3650)) {
            throw new DomainException('DEPOSIT_SETTINGS_INVALID', 'Enter a valid deposit amount and a waiting period from 0 to 3650 days.');
        }
        $minimum = $data['tron_minimum_deposit'] ?? null;
        if (array_key_exists('tron_minimum_deposit', $data) && (! is_string($minimum) || ! preg_match('/^\d{1,12}(?:\.\d{1,8})?$/D', $minimum))) {
            throw new DomainException('TOPUP_AMOUNT_INVALID', 'Enter a valid amount.');
        }
        $fee = $data['withdrawal_fee_percent'] ?? null;
        if (array_key_exists('withdrawal_fee_percent', $data) && (! is_string($fee) || ! preg_match('/^(?:0|[1-9][0-9]?)(?:\.\d{1,8})?$/', $fee))) {
            throw new DomainException('WITHDRAWAL_FEE_INVALID', 'Enter a withdrawal percentage from 0 up to but not including 100, with at most 8 decimal places.');
        }

        DB::transaction(function () use ($tenant, $data, $amount, $asset, $actor, $requestId, $fee, $waitDays, $minimum): void {
            $lockedTenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($asset !== null && $asset !== $lockedTenant->default_asset) {
                throw new DomainException('SECURITY_DEPOSIT_ASSET_MISMATCH', 'Security deposit asset must match the Tenant default asset.');
            }
            $settings = $lockedTenant->businessSettings()->lockForUpdate()->firstOrFail();
            $fields = ['required_security_deposit_amount', 'required_security_deposit_asset', 'security_deposit_refund_wait_days', 'allow_wallet_topup', 'allow_withdrawal', 'withdrawal_fee_percent', 'tron_minimum_deposit'];
            $before = $settings->only($fields);
            $settings->update([
                'required_security_deposit_amount' => $amount?->amount() ?? $settings->required_security_deposit_amount,
                'required_security_deposit_asset' => $asset ?? $settings->required_security_deposit_asset,
                'security_deposit_refund_wait_days' => array_key_exists('security_deposit_refund_wait_days', $data) ? $waitDays : $settings->security_deposit_refund_wait_days,
                'tron_minimum_deposit' => $minimum ?? $settings->tron_minimum_deposit,
                'allow_wallet_topup' => true,
                'allow_withdrawal' => true,
                'withdrawal_fee_percent' => $fee === null ? $settings->withdrawal_fee_percent : Money::of($fee, 'USDT')->amount(),
            ]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_BUSINESS_SETTINGS_UPDATED', 'tenant_business_settings', $tenant->id, $before, $settings->fresh()->only($fields), $requestId);
        });
    }
}
