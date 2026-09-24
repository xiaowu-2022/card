<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\PhotonPayMerchantReport;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final class RefreshCardFormFactors
{
    public function merchant(string $id, AdminUser $actor): void
    {
        $snapshot = CardProviderReference::findOrFail($id);
        $connection = $snapshot->photonpay_reporting_encrypted;
        if (! $connection) {
            throw new DomainException('CARD_CATALOG_UNAVAILABLE', 'Configure the merchant reporting connection first.', 409);
        }
        $bins = app(PhotonPayMerchantReport::class)->bins($connection, fresh: true);
        if ($bins === null) {
            throw new DomainException('CARD_CATALOG_UNAVAILABLE', 'BIN list is unavailable. Please try again.', 503);
        }
        DB::transaction(function () use ($id, $connection, $bins, $actor): void {
            $merchant = CardProviderReference::whereKey($id)->lockForUpdate()->firstOrFail();
            if ($merchant->photonpay_reporting_encrypted !== $connection) {
                throw new DomainException('CARD_CATALOG_CHANGED', 'Configuration changed. Refresh and try again.', 409);
            }
            $merchant->forceFill(['bin_catalog' => $bins])->save();
            foreach (CardProduct::where('card_provider_reference_id', $id)->lockForUpdate()->get() as $product) {
                $forms = collect($bins)->firstWhere('bin', $product->provider_product_ref)['formFactors'] ?? [];
                $product->forceFill(['supported_form_factors' => $forms, 'form_factors_synced_at' => now()])->save();
            }
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'CARD_CATALOG_REFRESHED', 'card_provider_reference', $id, null, ['bin_count' => count($bins)]);
        });
    }

    public function execute(string $id, AdminUser $actor): void
    {
        $snapshot = CardProduct::with('cardProviderReference')->findOrFail($id);
        $connection = $snapshot->cardProviderReference?->photonpay_reporting_encrypted;
        if (! $connection) {
            throw new DomainException('CARD_CATALOG_UNAVAILABLE', 'Configure the merchant reporting connection first.', 409);
        }
        $bins = app(PhotonPayMerchantReport::class)->bins($connection, fresh: true);
        if ($bins === null) {
            throw new DomainException('CARD_CATALOG_UNAVAILABLE', 'BIN list is unavailable. Please try again.', 503);
        }
        DB::transaction(function () use ($snapshot, $connection, $bins, $actor): void {
            $merchant = CardProviderReference::whereKey($snapshot->card_provider_reference_id)->lockForUpdate()->firstOrFail();
            $product = CardProduct::whereKey($snapshot->id)->lockForUpdate()->firstOrFail();
            if ($merchant->photonpay_reporting_encrypted !== $connection || $product->card_provider_reference_id !== $merchant->id || $product->provider_product_ref !== $snapshot->provider_product_ref) {
                throw new DomainException('CARD_CATALOG_CHANGED', 'Configuration changed. Refresh and try again.', 409);
            }
            $merchant->forceFill(['bin_catalog' => $bins])->save();
            $forms = collect($bins)->firstWhere('bin', $product->provider_product_ref)['formFactors'] ?? [];
            $before = $product->supported_form_factors;
            $product->forceFill(['supported_form_factors' => $forms, 'form_factors_synced_at' => now()])->save();
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'CARD_CAPABILITIES_REFRESHED', 'card_product', $product->id,
                ['forms' => $before], ['forms' => $forms]);
        });
    }
}
