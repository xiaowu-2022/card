<?php

namespace App\Jobs;

use App\Application\Payment\InitiateWalletTopupPaymentAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\Tenant\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class InitiateWalletTopupPaymentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $orderId,
        public readonly string $returnUrl,
    ) {}

    public function handle(InitiateWalletTopupPaymentAction $action, TenantContext $context): void
    {
        $context->set(Tenant::query()->whereKey($this->tenantId)->firstOrFail());
        try {
            $action->execute($this->tenantId, $this->orderId, $this->returnUrl);
        } finally {
            $context->clear();
        }
    }
}
