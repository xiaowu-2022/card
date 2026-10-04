<?php

namespace App\Jobs;

use App\Application\Card\BatchCardTransactionSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

final class SyncCardTransactionPage implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return $this->itemId;
    }

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public readonly string $itemId) {}

    public function handle(BatchCardTransactionSync $sync): void
    {
        // Browser batches must never progress through recovery or a stale queued job.
        $queued = DB::table('card_transaction_sync_items as i')
            ->join('card_transaction_sync_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.id', $this->itemId)->where('b.execution_mode', 'queue')->exists();
        if ($queued) {
            $sync->process($this->itemId);
        }
    }
}
