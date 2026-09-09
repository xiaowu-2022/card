<?php

namespace App\Console\Commands;

use App\Domain\Ledger\Services\LedgerReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class ReconcileLedger extends Command
{
    protected $signature = 'ledger:reconcile {--tenant= : Limit reconciliation to one Tenant UUID} {--account= : Limit reconciliation to one Ledger Account UUID}';

    protected $description = 'Compare cached ledger account balances with immutable posting totals without modifying data';

    public function handle(LedgerReconciliationService $reconciliation): int
    {
        $mismatches = $reconciliation->mismatches($this->option('tenant'), $this->option('account'));
        if ($mismatches === []) {
            $this->info('Ledger reconciliation passed. No balance mismatches found.');

            return self::SUCCESS;
        }

        $this->table(['Tenant', 'Account', 'Type', 'Asset', 'Cached', 'Postings', 'Difference'], array_map(fn (array $row): array => array_values($row), $mismatches));
        foreach ($mismatches as $row) {
            Log::error('Ledger reconciliation mismatch.', $row);
        }
        $this->error(count($mismatches).' ledger balance mismatch(es) found. No data was changed.');

        return self::FAILURE;
    }
}
