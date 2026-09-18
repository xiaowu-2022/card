<?php

namespace App\Application\Wealth;

use App\Application\Assets\AssetAccess;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Assets\AssetCatalog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Wealth\WealthMath;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class WealthConfiguration
{
    public function get(string $tenantId): array
    {
        $settings = DB::table('wealth_settings')->where('tenant_id', $tenantId)->get()->keyBy('asset_code');

        return array_map(function ($asset) use ($settings) {
            $row = $settings->get($asset);

            return ['asset' => $asset, 'minimum' => $row?->minimum === null ? '' : Money::of($row->minimum, $asset)->amount(), 'revision' => $row?->revision,
                'products' => $row ? json_decode($row->products, true) : array_map(fn ($months, $rate) => ['months' => $months, 'rate' => $rate, 'enabled' => false], array_keys(WealthMath::RATES), array_values(WealthMath::RATES))];
        }, AssetCatalog::ASSETS);
    }

    public function save(AdminUser $actor, string $tenantId, array $input): void
    {
        app(AssetAccess::class)->platform($actor, 'tenant.manage');
        $data = Validator::make($input, ['settings' => 'required|array|size:4', 'settings.*.asset' => 'required|distinct|in:USDT,USDC,ETH,BTC', 'settings.*.minimum' => ['nullable', 'string', 'regex:/^\d{1,12}(?:\.\d{1,18})?$/D'], 'settings.*.revision' => 'nullable|uuid', 'settings.*.products' => 'required|array|size:7', 'settings.*.products.*.months' => 'required|integer|in:1,3,6,12,24,36,60', 'settings.*.products.*.rate' => ['required', 'string', 'regex:/^\d{1,3}(?:\.\d{1,8})?$/D'], 'settings.*.products.*.enabled' => 'required|boolean'])->validate();
        DB::transaction(function () use ($actor, $tenantId, $data) {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            AdminUser::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(AssetAccess::class)->platform($actor, 'tenant.manage');
            $before = $this->get($tenantId);
            foreach ($data['settings'] as $i => $row) {
                $existing = DB::table('wealth_settings')->where('tenant_id', $tenantId)->where('asset_code', $row['asset'])->first();
                if (($existing?->revision) !== ($row['revision'] ?? null)) {
                    throw ValidationException::withMessages(['form' => 'Wealth settings changed. Reload and review.']);
                }
                $minimum = null;
                if (($row['minimum'] ?? '') !== '') {
                    try {
                        $minimum = Money::of($row['minimum'], $row['asset']);
                    } catch (\Throwable) {
                        throw ValidationException::withMessages(["settings.$i.minimum" => 'Enter a positive amount with valid currency precision.']);
                    }
                    if (! $minimum->isPositive()) {
                        throw ValidationException::withMessages(["settings.$i.minimum" => 'Enter a positive amount with valid currency precision.']);
                    }
                }
                if (count(array_unique(array_column($row['products'], 'months'))) !== 7) {
                    throw ValidationException::withMessages(['form' => 'Select each wealth term exactly once.']);
                }
                foreach ($row['products'] as $j => $product) {
                    if (BigDecimal::of($product['rate'])->multipliedBy($product['months'])->isGreaterThanOrEqualTo('1200')) {
                        throw ValidationException::withMessages(["settings.$i.products.$j.rate" => 'Total term interest must be less than the principal.']);
                    }
                    if ($product['enabled'] && ! $minimum) {
                        throw ValidationException::withMessages(["settings.$i.minimum" => 'Set a minimum amount before enabling wealth products.']);
                    }
                }
                DB::table('wealth_settings')->updateOrInsert(['tenant_id' => $tenantId, 'asset_code' => $row['asset']], ['id' => $existing?->id ?? (string) Str::uuid(), 'minimum' => $minimum?->amount(), 'products' => json_encode($row['products']), 'revision' => (string) Str::uuid(), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
            }
            app(AuditLogger::class)->record($tenantId, 'ADMIN', $actor->id, 'WEALTH_SETTINGS_UPDATED', 'tenant', $tenantId, ['settings' => $before], ['settings' => $this->get($tenantId)]);
        }, 3);
    }
}
