<?php

namespace App\Jobs;

use App\Application\Card\BatchCardTransactionSync;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

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
        $sync->process($this->itemId);
    }
}
