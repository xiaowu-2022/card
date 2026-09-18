<?php

namespace App\Console\Commands;

use App\Application\Wealth\WealthService;
use App\Domain\Wealth\WealthOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecoverWealth extends Command
{
    protected $signature = 'wealth:recover';

    protected $description = 'Settle due wealth installments and matured principal idempotently';

    public function handle(WealthService $service): int
    {
        $failures = 0;
        WealthOrder::where('status', 'ACTIVE')->where(function ($q) {
            $q->where('matures_at', '<=', now())->orWhereExists(fn ($i) => $i->selectRaw('1')->from('wealth_installments')->whereColumn('order_id', 'wealth_orders.id')->whereColumn('tenant_id', 'wealth_orders.tenant_id')->whereNull('settled_at')->where('due_at', '<=', now()));
        })->chunkById(100, function ($orders) use ($service, &$failures) {
            foreach ($orders as $order) {
                try {
                    $service->settle($order->tenant_id, $order->id);
                } catch (\Throwable $e) {
                    $failures++;
                    Log::warning('Wealth settlement pending retry', ['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'error_class' => $e::class]);
                }
            }
        });
        $this->info('Wealth recovery completed; pending failures: '.$failures);

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
