<?php

namespace App\Console\Commands;

use App\Application\Wealth\WealthService;
use App\Domain\Wealth\WealthOrder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecoverWealth extends Command
{
    protected $signature = 'wealth:recover';

    protected $description = 'Settle wealth interest, return legacy principal and recover automatic renewals idempotently';

    public function handle(WealthService $service): int
    {
        $failures = 0;
        $processed = [];
        $budget = 1000;
        $due = WealthOrder::where('status', 'ACTIVE')->where(function ($q) {
            $q->where(fn ($q) => $q->whereNull('maturity_policy')->where('matures_at', '<=', now()))
                ->orWhere('redeem_before', '<=', now())->orWhereExists(fn ($i) => $i->selectRaw('1')->from('wealth_installments')->whereColumn('order_id', 'wealth_orders.id')->whereColumn('tenant_id', 'wealth_orders.tenant_id')->whereNull('settled_at')->where('due_at', '<=', now()));
        });
        (clone $due)->chunkById(100, function ($orders) use ($service, &$failures, &$processed, &$budget) {
            foreach ($orders as $order) {
                // Each cycle commits separately. Both per-chain and total work are bounded.
                for ($cycle = 0; $cycle < 12 && $order !== null; $cycle++) {
                    if (isset($processed[$order->id])) {
                        break;
                    }
                    if ($budget-- <= 0) {
                        return false;
                    }
                    $processed[$order->id] = true;
                    try {
                        $service->settle($order->tenant_id, $order->id);
                        $order = WealthOrder::where('tenant_id', $order->tenant_id)->where('previous_order_id', $order->id)->first();
                    } catch (\Throwable $e) {
                        $failures++;
                        Log::warning('Wealth settlement pending retry', ['tenant_id' => $order->tenant_id, 'order_id' => $order->id, 'error_class' => $e::class]);
                        break;
                    }
                }
                // A later chunk must not reset this chain's allowance in the same invocation.
                if ($order !== null) {
                    $processed[$order->id] = true;
                }
            }
        });
        $pending = (clone $due)->count();
        $this->info('Wealth recovery completed; pending failures: '.$failures.'; due orders remaining: '.$pending);
        if ($pending > 0) {
            Log::notice('Wealth recovery backlog', ['due_orders' => $pending, 'failures' => $failures]);
        }

        return $failures ? self::FAILURE : self::SUCCESS;
    }
}
