<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class CreateCardProductAction
{
    public function __construct(private AuditLogger $audit, private CardProductBinPolicy $bins) {}

    /** @param array{name:string,provider_product_ref:string,minimum_initial_load:string,minimum_reload:string,status:string} $data */
    public function execute(array $data, AdminUser $actor, ?string $requestId = null): CardProduct
    {
        $fee = Validator::make($data, ['opening_fee' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/']])->validate()['opening_fee'];
        Validator::make($data, ['balance_limit' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/']])->validate();
        $openingFee = Money::of($fee, 'USDT')->amount();
        $initial = $this->minimum($data['minimum_initial_load'], 'minimum initial load');
        $reload = $this->minimum($data['minimum_reload'], 'minimum reload');

        $connection = $this->bins->prepare($data['card_provider_reference_id'] ?? null, trim($data['provider_product_ref'] ?? ''));

        return DB::transaction(function () use ($data, $openingFee, $initial, $reload, $actor, $requestId, $connection): CardProduct {
            $this->bins->lockAndValidate($data['card_provider_reference_id'] ?? null, trim($data['provider_product_ref'] ?? ''), $connection);
            $product = new CardProduct;
            $product->forceFill([
                'provider' => 'UNCONFIGURED',
                'card_provider_reference_id' => $data['card_provider_reference_id'] ?? null,
                'provider_product_ref' => trim($data['provider_product_ref'] ?? ''),
                'name' => trim($data['name']),
                'opening_fee' => $openingFee,
                'balance_limit' => isset($data['balance_limit']) ? Money::of($data['balance_limit'], 'USD')->amount() : null,
                'card_currency' => 'USD',
                'card_type' => 'REGULAR',
                'minimum_initial_load' => $initial->amount(),
                'minimum_reload' => $reload->amount(),
                'status' => $data['status'],
            ])->save();
            $this->audit->record(null, 'ADMIN', $actor->id, 'CARD_PRODUCT_CREATED', 'card_product', $product->id, null, [
                'provider' => $product->provider,
                'card_provider_reference_id' => $product->card_provider_reference_id,
                'provider_product_ref' => $product->provider_product_ref,
                'name' => $product->name,
                'opening_fee' => $product->opening_fee,
                'balance_limit' => $product->balance_limit,
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
