<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTenantBusinessSettingsAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{required_security_deposit_amount:string,required_security_deposit_asset:string,allow_wallet_topup:bool,allow_withdrawal:bool} $data */
    public function execute(Tenant $tenant, array $data, AdminUser $actor, ?string $requestId = null): void
    {
        $amount = Money::of($data['required_security_deposit_amount'], $data['required_security_deposit_asset']);
        if (! $amount->isZero() && ! $amount->isPositive()) {
            throw new DomainException('NEGATIVE_DEPOSIT_REQUIREMENT', 'Security deposit requirement cannot be negative.');
        }

        DB::transaction(function () use ($tenant, $data, $amount, $actor, $requestId): void {
            $settings = $tenant->businessSettings()->lockForUpdate()->firstOrFail();
            $fields = ['required_security_deposit_amount', 'required_security_deposit_asset', 'allow_wallet_topup', 'allow_withdrawal'];
            $before = $settings->only($fields);
            $settings->update([
                'required_security_deposit_amount' => $amount->amount(),
                'required_security_deposit_asset' => $data['required_security_deposit_asset'],
                'allow_wallet_topup' => $data['allow_wallet_topup'],
                'allow_withdrawal' => $data['allow_withdrawal'],
            ]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'TENANT_BUSINESS_SETTINGS_UPDATED', 'tenant_business_settings', $tenant->id, $before, $settings->fresh()->only($fields), $requestId);
        });
    }
}
