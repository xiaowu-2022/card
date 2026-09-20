<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\Ledger\ValueObjects\Money;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final readonly class UpdateCardProductAction
{
    public function __construct(private AuditLogger $audit, private CardProductBinPolicy $bins) {}

    /** @param array{name:string,provider_product_ref:string,minimum_initial_load:string,minimum_reload:string,status:string} $data */
    public function execute(string $productId, array $data, AdminUser $actor, ?string $requestId = null): CardProduct
    {
        $fee = Validator::make($data, ['opening_fee' => ['required', 'string', 'regex:/^\d{1,12}(?:\.\d{1,8})?$/']])->validate()['opening_fee'];
        Validator::make($data, ['balance_limit' => ['nullable', 'string', 'regex:/^(?:0|[1-9][0-9]{0,9})(?:\.[0-9]{1,2})?$/']])->validate();
        $openingFee = Money::of($fee, 'USDT')->amount();
        $initial = $this->minimum($data['minimum_initial_load'], 'minimum initial load');
        $reload = $this->minimum($data['minimum_reload'], 'minimum reload');

        $snapshot = CardProduct::query()->findOrFail($productId);
        if ($snapshot->archived_at !== null) {
            throw new DomainException('CARD_PRODUCT_ARCHIVED', 'This card product has been archived.', 409);
        }
        $requestedBinding = array_key_exists('card_provider_reference_id', $data) ? $data['card_provider_reference_id'] : $snapshot->card_provider_reference_id;
        $requestedBin = trim($data['provider_product_ref'] ?? '');
        $changed = $requestedBinding !== $snapshot->card_provider_reference_id || $requestedBin !== $snapshot->provider_product_ref;
        $connection = $changed ? $this->bins->prepare($requestedBinding, $requestedBin) : null;

        return DB::transaction(function () use ($productId, $data, $openingFee, $initial, $reload, $actor, $requestId, $snapshot, $changed, $connection): CardProduct {
            $product = CardProduct::query()->whereKey($productId)->lockForUpdate()->firstOrFail();
            if ($product->archived_at !== null) {
                throw new DomainException('CARD_PRODUCT_ARCHIVED', 'This card product has been archived.', 409);
            }
            if ($product->card_provider_reference_id !== $snapshot->card_provider_reference_id || $product->provider_product_ref !== $snapshot->provider_product_ref) {
                throw new DomainException('CARD_PRODUCT_ROUTING_LOCKED', 'Product routing changed. Refresh before editing.', 409);
            }
            $binding = array_key_exists('card_provider_reference_id', $data) ? $data['card_provider_reference_id'] : $product->card_provider_reference_id;
            $reference = trim($data['provider_product_ref'] ?? '');
            if (($binding !== $product->card_provider_reference_id || $reference !== $product->provider_product_ref) && (
                DB::table('provider_cardholders')->where('card_product_id', $productId)->exists()
                || DB::table('card_issue_orders')->where('card_product_id', $productId)->exists()
                || DB::table('user_cards')->where('card_product_id', $productId)->exists()
            )) {
                throw new DomainException('CARD_PRODUCT_ROUTING_LOCKED', 'This product has card history. Create a new product to change its card provider or BIN.', 409);
            }
            if ($changed) {
                $this->bins->lockAndValidate($binding, $reference, $connection, $productId);
            }
            $before = $product->only(['provider', 'card_provider_reference_id', 'provider_product_ref', 'name', 'opening_fee', 'balance_limit', 'minimum_initial_load', 'minimum_reload', 'status']);
            $product->forceFill([
                'provider' => $binding !== $product->card_provider_reference_id ? 'UNCONFIGURED' : $product->provider,
                'card_provider_reference_id' => $binding,
                'provider_product_ref' => $reference,
                'name' => trim($data['name']),
                'opening_fee' => $openingFee,
                'balance_limit' => array_key_exists('balance_limit', $data) ? (isset($data['balance_limit']) ? Money::of($data['balance_limit'], 'USD')->amount() : null) : $product->balance_limit,
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
