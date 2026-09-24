<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class InspectPhotonPayAccounts extends Command
{
    protected $signature = 'cards:accounts-preflight';

    protected $description = 'Read-only PhotonPay routing, migration and permanent BIN reservation report';

    public function handle(): int
    {
        $legacy = DB::table('card_products')->where('provider', 'PHOTONPAY')->whereNull('card_provider_reference_id')->pluck('id');
        $this->line('Legacy environment-dependent products: '.json_encode($legacy));
        $duplicates = DB::table('card_products')->select('provider_product_ref')->where('provider_product_ref', '!=', '')->groupBy('provider_product_ref')->havingRaw('count(*) > 1')->pluck('provider_product_ref');
        foreach ($duplicates as $bin) {
            $this->line(json_encode(['bin' => $bin, 'products' => DB::table('card_products')->where('provider_product_ref', $bin)->get(['id', 'card_provider_reference_id', 'archived_at'])]));
        }
        if (! Schema::hasTable('card_bin_claims')) {
            $this->warn('Account/BIN migration has not been applied. Re-run after migration.');

            return self::FAILURE;
        }
        $conflicts = DB::table('card_bin_claims')->where('conflicted', true)->pluck('bin');
        $unready = DB::table('platform_card_provider_references')->where(fn ($q) => $q->whereNotNull('photonpay_issuing_encrypted')->orWhereNotNull('photonpay_reporting_encrypted'))->where(function ($q): void {
            $q->whereNotNull('photonpay_migration_error')->orWhereNull('photonpay_identity')->orWhereNull('photonpay_webhook_key_encrypted')->orWhere('photonpay_check_status', '!=', 'VERIFIED');
        })->get(['id', 'name', 'photonpay_migration_error']);
        $this->line(json_encode(['conflicted_bins' => $conflicts, 'accounts_requiring_review' => $unready, 'permanent_claim_count' => DB::table('card_bin_claims')->count()]));

        return $legacy->isEmpty() && $conflicts->isEmpty() && $unready->isEmpty() ? self::SUCCESS : self::FAILURE;
    }
}
