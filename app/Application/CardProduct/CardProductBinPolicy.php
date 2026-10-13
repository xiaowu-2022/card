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
        $connection = $this->connection($record);

        return $connection;
    }

    /** Called in the product transaction; serializes allocation on the merchant row. */
    public function lockAndValidate(?string $binding, string $bin, ?string $connection, ?string $except = null): void
    {
        if (! $binding) {
            throw ValidationException::withMessages(['card_provider_reference_id' => 'Select a configured PhotonPay account.']);
        }
        $record = CardProviderReference::whereKey($binding)->lockForUpdate()->firstOrFail();
        if ($this->connection($record) !== $connection) {
            throw ValidationException::withMessages(['provider_product_ref' => 'Account configuration changed. Refresh and try again.']);
        }
        $this->assertSelectable($record, $bin);
        $bin = $this->sourceBin($binding, $bin) ?? $bin;
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?,0))', ['card-bin:'.$bin]);
        if (DB::table('card_bin_claims')->where('bin', $bin)->when($except, fn ($q) => $q->where(fn ($q) => $q->where('card_product_id', '<>', $except)->orWhereNull('card_product_id')))->exists()) {
            throw ValidationException::withMessages(['provider_product_ref' => 'This BIN is already used by an existing product.']);
        }
    }

    public function sourceBin(?string $binding, string $reference): ?string
    {
        $u1 = DB::table('u1_connections')->where('reference_id', $binding)->first();

        return $u1 ? (collect(json_decode($u1->group_catalog ?? '[]', true))->firstWhere('groupNo', $reference)['bin'] ?? null) : null;
    }

    private function connection(CardProviderReference $record): ?string
    {
        $u1 = DB::table('u1_connections')->where('reference_id', $record->id)->first();

        return $u1 ? 'u1:'.hash('sha256', json_encode([$u1->customer_no, $u1->base_url, $u1->api_key_encrypted, $u1->enabled, $u1->check_status, $u1->group_catalog], JSON_THROW_ON_ERROR)) : $record->photonpay_issuing_encrypted;
    }

    private function assertSelectable(CardProviderReference $record, string $bin): void
    {
        $u1 = DB::table('u1_connections')->where('reference_id', $record->id)->first();
        if ($u1) {
            $group = collect(json_decode($u1->group_catalog ?? '[]', true))->firstWhere('groupNo', $bin);
            if (! $u1->enabled || $u1->check_status !== 'VERIFIED' || ($group['currency'] ?? null) !== 'USD') {
                throw ValidationException::withMessages(['provider_product_ref' => 'Select an enabled and verified U1 card group.']);
            }

            return;
        }
        if (! $record->photonpay_enabled || ! $record->photonpay_issuing_encrypted || ! $record->photonpay_webhook_key_encrypted || ! $record->photonpay_identity || $record->photonpay_migration_error || $record->photonpay_check_status !== 'VERIFIED') {
            throw ValidationException::withMessages(['card_provider_reference_id' => 'Select an enabled and verified PhotonPay account.']);
        }
        if (! in_array($bin, array_column($record->bin_catalog ?? [], 'bin'), true)) {
            throw ValidationException::withMessages(['provider_product_ref' => 'Select a BIN from the saved catalog of this account.']);
        }
    }
}
