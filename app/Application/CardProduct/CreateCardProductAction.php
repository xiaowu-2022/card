<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CreateCardProductAction
{
    public function __construct(private AuditLogger $audit) {}

    /** @param array{name:string,provider_product_ref:string,minimum_initial_load:string,minimum_reload:string,status:string} $data */
    public function execute(array $data, AdminUser $actor, ?string $requestId = null): CardProduct
    {
        $initial = $this->minimum($data['minimum_initial_load'], 'minimum initial load');
        $reload = $this->minimum($data['minimum_reload'], 'minimum reload');

        return DB::transaction(function () use ($data, $initial, $reload, $actor, $requestId): CardProduct {
            $product = new CardProduct;
            $product->forceFill([
                'provider' => 'PHOTONPAY',
                'provider_product_ref' => trim($data['provider_product_ref']),
                'name' => trim($data['name']),
                'card_currency' => 'USD',
                'card_type' => 'REGULAR',
                'minimum_initial_load' => $initial->amount(),
                'minimum_reload' => $reload->amount(),
                'status' => $data['status'],
            ])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, 'CARD_PRODUCT_CREATED', 'card_product', $product->id, null, [
                'provider' => $product->provider,
                'provider_product_ref' => $product->provider_product_ref,
                'name' => $product->name,
                'card_currency' => $product->card_currency,
                'card_type' => $product->card_type,
                'minimum_initial_load' => $product->minimum_initial_load,
                'minimum_reload' => $product->minimum_reload,
                'status' => $product->status->value,
            ], $requestId);

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
