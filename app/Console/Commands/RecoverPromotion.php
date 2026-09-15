<?php

namespace App\Console\Commands;

use App\Application\SecurityDeposit\AllocateInitialDepositAction;
use App\Domain\SecurityDeposit\Models\InitialDepositIntent;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class RecoverPromotion extends Command
{
    protected $signature = 'promotion:recover {--tenant=}';

    protected $description = 'Retry durable initial deposit allocation. Timed refunds use deposits:process-refunds.';

    public function handle(AllocateInitialDepositAction $initial): int
    {
        $selected = $this->option('tenant');
        if ($selected && ! Str::isUuid($selected)) {
            return self::INVALID;
        }
        foreach (Tenant::query()->when($selected, fn ($q) => $q->whereKey($selected))->orderBy('id')->cursor() as $tenant) {
            foreach (InitialDepositIntent::query()->where('tenant_id', $tenant->id)->where('status', 'PENDING')->oldest('updated_at')->limit(100)->get() as $intent) {
                try {
                    $initial->execute($tenant->id, $intent->id);
                } catch (\Throwable) { /* Retain the durable intent; never undo credited money. */
                }
                InitialDepositIntent::query()->where('tenant_id', $tenant->id)->whereKey($intent->id)->where('status', 'PENDING')->update(['updated_at' => now()]);
            }
        }

        return self::SUCCESS;
    }
}
