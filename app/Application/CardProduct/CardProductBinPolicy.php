<?php

namespace App\Application\CardProduct;

use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Validation\ValidationException;

final readonly class CardProductBinPolicy
{
    public function __construct(private PhotonPayMerchantReport $provider) {}

    /** Fetch before the write transaction; retain the exact connection for a locked recheck. */
    public function prepare(?string $binding, string $bin): ?string
    {
        if (! $binding) {
            return null;
        }
        $record = CardProviderReference::query()->findOrFail($binding);
        $connection = $record->photonpay_reporting_encrypted;
        if ($connection !== null) {
            $bins = $this->provider->bins($connection, fresh: true);
            if ($bins === null) {
                throw ValidationException::withMessages(['provider_product_ref' => 'BIN list is unavailable. Please try again.']);
            }
            if (! in_array($bin, array_column($bins, 'bin'), true)) {
                throw ValidationException::withMessages(['provider_product_ref' => 'Select a BIN returned by PhotonPay.']);
            }
        }

        return $connection;
    }

    /** Called in the product transaction; serializes allocation on the merchant row. */
    public function lockAndValidate(?string $binding, string $bin, ?string $connection, ?string $except = null): void
    {
        if (! $binding) {
            return;
        }
        $record = CardProviderReference::query()->whereKey($binding)->lockForUpdate()->firstOrFail();
        if ($record->photonpay_reporting_encrypted !== $connection) {
            throw ValidationException::withMessages(['provider_product_ref' => 'BIN connection changed. Refresh and try again.']);
        }
        if ($bin !== '' && CardProduct::query()->where('card_provider_reference_id', $binding)
            ->whereNull('archived_at')
            ->where('provider_product_ref', $bin)->when($except, fn ($query) => $query->where('id', '!=', $except))->exists()) {
            throw ValidationException::withMessages(['provider_product_ref' => 'This BIN is already used by another product for this card provider.']);
        }
    }
}
