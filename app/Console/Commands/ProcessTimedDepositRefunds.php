<?php

namespace App\Console\Commands;

use App\Application\SecurityDeposit\TimedDepositRefundAction;
use App\Domain\SecurityDeposit\Models\SecurityDepositRefundRequest;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class ProcessTimedDepositRefunds extends Command
{
    protected $signature = 'deposits:process-refunds {--tenant=} {--watch : Run the dedicated refund worker every 30 seconds}';

    protected $description = 'Process only user-authorized timed deposit refunds; never replay legacy refunds or unrelated jobs.';

    public function handle(TimedDepositRefundAction $action): int
    {
        $tenantId = $this->option('tenant');
        if ($tenantId && ! Str::isUuid($tenantId)) {
            return self::INVALID;
        }
        do {
            foreach (Tenant::query()->where('status', 'ACTIVE')->when($tenantId, fn ($q) => $q->whereKey($tenantId))->orderBy('id')->cursor() as $tenant) {
                foreach (SecurityDepositRefundRequest::query()->where('tenant_id', $tenant->id)->where('status', 'CHECKING')
                    ->whereNotNull('refund_wait_days')->oldest('updated_at')->orderBy('id')->limit(100)->get() as $refund) {
                    $action->execute($tenant->id, $refund->user_id, $refund->id);
                }
            }
            if ($this->option('watch')) {
                sleep(30);
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }
}
