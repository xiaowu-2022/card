<?php

namespace App\Jobs;

use App\Application\Payment\CreditWalletTopupAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
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

    public function handle(CreditWalletTopupAction $action, TenantContext $context): void
    {
        $context->set(Tenant::query()->whereKey($this->tenantId)->firstOrFail());
        try {
            $action->execute($this->tenantId, $this->orderId);
        } finally {
            $context->clear();
        }
    }
}
