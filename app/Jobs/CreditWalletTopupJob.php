<?php

namespace App\Jobs;

use App\Application\Payment\CreditWalletTopupAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class CreditWalletTopupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 10;

    public function __construct(public readonly string $tenantId, public readonly string $orderId) {}

    public function handle(CreditWalletTopupAction $action): void
    {
        $action->execute($this->tenantId, $this->orderId);
    }
}
