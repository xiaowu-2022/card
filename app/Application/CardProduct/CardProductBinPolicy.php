<?php

namespace App\Application\CardProduct;

use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class CardProductBinPolicy
{
    public function __construct(private PhotonPayMerchantReport $provider) {}

    /** Fetch before the write transaction; retain the exact connection for a locked recheck. */
    public function prepare(?string $binding, string $bin): ?string
    {
        if (! $binding || $bin === '') {
            throw ValidationException::withMessages(['card_provider_reference_id' => 'Select a configured PhotonPay account and BIN.']);
        }
        $record = CardProviderReference::findOrFail($binding);
        $this->assertSelectable($record, $bin);
        $connection = $record->photonpay_issuing_encrypted;

        return $connection;
    }

    /** Called in the product transaction; serializes allocation on the merchant row. */
    public function lockAndValidate(?string $binding, string $bin, ?string $connection, ?string $except = null): void
    {
        if (! $binding) {
            throw ValidationException::withMessages(['card_provider_reference_id' => 'Select a configured PhotonPay account.']);
        }
        $record = CardProviderReference::whereKey($binding)->lockForUpdate()->firstOrFail();
        if ($record->photonpay_issuing_encrypted !== $connection) {
            throw ValidationException::withMessages(['provider_product_ref' => 'Account configuration changed. Refresh and try again.']);
        }
        $this->assertSelectable($record, $bin);
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['card-bin:'.$bin]);
        if (DB::table('card_bin_claims')->where('bin', $bin)->exists()) {
            throw ValidationException::withMessages(['provider_product_ref' => 'This BIN is already used by an existing product.']);
        }
    }

    private function assertSelectable(CardProviderReference $record, string $bin): void
    {
        if (! $record->photonpay_enabled || ! $record->photonpay_issuing_encrypted || ! $record->photonpay_webhook_key_encrypted || ! $record->photonpay_identity || $record->photonpay_migration_error || $record->photonpay_check_status !== 'VERIFIED') {
            throw ValidationException::withMessages(['card_provider_reference_id' => 'Select an enabled and verified PhotonPay account.']);
        }
        if (! in_array($bin, array_column($record->bin_catalog ?? [], 'bin'), true)) {
            throw ValidationException::withMessages(['provider_product_ref' => 'Select a BIN from the saved catalog of this account.']);
        }
    }
}
