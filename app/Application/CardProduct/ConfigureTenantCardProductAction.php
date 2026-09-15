<?php

namespace App\Application\CardProduct;

use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Enums\CardProductStatus;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class ConfigureTenantCardProductAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{display_name:?string,opening_fee:string,max_cards_per_user:int,status:string,sort_order:int} $data */
    public function execute(string $tenantId, string $productId, array $data, AdminUser $actor, ?string $requestId = null): TenantCardProductConfig
    {
        app(CompanyConfigurationAuthority::class)->assert($actor);
        Validator::make($data, ['opening_fee' => ['prohibited']])->validate();

        return DB::transaction(function () use ($tenantId, $productId, $data, $actor, $requestId): TenantCardProductConfig {
            $product = CardProduct::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            if ($data['status'] === 'ACTIVE' && $product->status !== CardProductStatus::Active) {
                throw new DomainException('CARD_PRODUCT_NOT_ACTIVE', 'Only an active platform product can be offered.', 409);
            }
            $config = TenantCardProductConfig::query()->where('tenant_id', $tenantId)
                ->where('card_product_id', $productId)->lockForUpdate()->first();
            $before = $config?->only(['display_name', 'opening_fee', 'max_cards_per_user', 'status', 'sort_order']);
            $config ??= new TenantCardProductConfig;
            $config->forceFill([
                'tenant_id' => $tenantId,
                'card_product_id' => $productId,
                'display_name' => filled($data['display_name']) ? trim((string) $data['display_name']) : null,
                'opening_fee' => $config->exists ? $config->opening_fee : ($product->opening_fee ?? '0.00000000'),
                'max_cards_per_user' => $data['max_cards_per_user'],
                'status' => $data['status'],
                'sort_order' => $data['sort_order'],
            ])->save();
            $after = $config->fresh()->only(['display_name', 'opening_fee', 'max_cards_per_user', 'status', 'sort_order']);
            $this->audit->record($tenantId, 'ADMIN', $actor->id, 'TENANT_CARD_PRODUCT_CONFIGURED', 'tenant_card_product_config', $config->id, $before, $after, $requestId);

            return $config;
        });
    }
}
