<?php

namespace App\Console\Commands;

use App\Application\Payment\PaymentLedgerReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcilePayments extends Command
{
    protected $signature = 'payments:reconcile {--tenant= : Limit reconciliation to one trusted Tenant UUID}';

    protected $description = 'Verify credited top-ups against their exact immutable Ledger entries without modifying data';

    public function handle(PaymentLedgerReconciliationService $reconciliation): int
    {
        $mismatches = $reconciliation->mismatches($this->option('tenant'));
        if ($mismatches === []) {
            $this->info('Payment reconciliation passed. All credited top-ups match their exact Ledger entries.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Top-up order', 'Issue'], array_map('array_values', $mismatches));
        foreach ($mismatches as $mismatch) {
            Log::error('Payment-to-ledger reconciliation mismatch.', $mismatch);
        }
        $this->error(count($mismatches).' payment reconciliation mismatch(es) found. No data was changed.');

        return self::FAILURE;
    }
}
