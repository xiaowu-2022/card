<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class UpdateCardProductAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{name:string,provider_product_ref:string,minimum_initial_load:string,minimum_reload:string,status:string} $data */
    public function execute(string $productId, array $data, AdminUser $actor, ?string $requestId = null): CardProduct
    {
        $initial = $this->minimum($data['minimum_initial_load'], 'minimum initial load');
        $reload = $this->minimum($data['minimum_reload'], 'minimum reload');

        return DB::transaction(function () use ($productId, $data, $initial, $reload, $actor, $requestId): CardProduct {
            $product = CardProduct::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            $before = $product->only(['provider_product_ref', 'name', 'minimum_initial_load', 'minimum_reload', 'status']);
            $product->forceFill([
                'provider_product_ref' => trim($data['provider_product_ref']),
                'name' => trim($data['name']),
                'minimum_initial_load' => $initial->amount(),
                'minimum_reload' => $reload->amount(),
                'status' => $data['status'],
            ])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, 'CARD_PRODUCT_UPDATED', 'card_product', $product->id, $before, $product->fresh()->only(array_keys($before)), $requestId);

            return $product;
        });
    }

    private function minimum(string $amount, string $label): Money
    {
        $money = Money::of($amount, 'USD');
        if ($money->compare(Money::of('20', 'USD')) < 0) {
            throw new DomainException('CARD_PRODUCT_MINIMUM_INVALID', "The {$label} cannot be below 20 USD.");
        }

        return $money;
    }
}
