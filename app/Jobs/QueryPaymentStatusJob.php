<?php

namespace App\Jobs;

use App\Application\Payment\QueryPaymentStatusAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class QueryPaymentStatusJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(public readonly string $tenantId, public readonly string $transactionId) {}

    public function handle(QueryPaymentStatusAction $action): void
    {
        $action->execute($this->tenantId, $this->transactionId);
    }
}
