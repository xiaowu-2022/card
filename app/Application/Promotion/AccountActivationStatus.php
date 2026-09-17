<?php

namespace App\Application\Promotion;

use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class AccountActivationStatus
{
    public function __construct(private PaidPromotionRules $rules) {}

    public function get(string $tenant, string $user): array
    {
        $company = Tenant::query()->whereKey($tenant)->firstOrFail();
        User::query()->where('tenant_id', $tenant)->whereKey($user)->firstOrFail();
        $cycle = $this->rules->cycle($tenant, $user);
        $settings = $company->businessSettings;
        $required = Money::of($settings->required_security_deposit_amount, 'USDT');
        $deposit = Money::of(DB::table('ledger_accounts')->where('tenant_id', $tenant)->where('user_id', $user)->where('asset_code', 'USDT')->where('account_type', 'USER_SECURITY_DEPOSIT')->value('balance') ?? '0', 'USDT');
        $remaining = $required->subtract($deposit);
        $supported = $company->default_asset === 'USDT' && $settings->required_security_deposit_asset === 'USDT';
        $ordinary = $supported && $deposit->compare($required) >= 0;

        return ['qualified' => $supported && ($cycle !== null || $ordinary), 'agent' => $cycle !== null,
            'rank' => $cycle?->rank ?? 0, 'endsAt' => $cycle?->ends_at,
            'ordinaryAvailable' => $supported && $required->isPositive(), 'depositSatisfied' => $ordinary,
            'depositRequired' => $required->amount(), 'depositCurrent' => $deposit->amount(),
            'depositRemaining' => $remaining->isNegative() ? '0.00000000' : $remaining->amount(),
            'refundPending' => DB::table('security_deposit_refund_requests')->where('tenant_id', $tenant)->where('user_id', $user)->where('status', 'CHECKING')->exists()];
    }
}
