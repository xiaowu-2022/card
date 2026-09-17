<?php

namespace App\Console\Commands;

use App\Application\Promotion\PaidPromotionRebate;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RecoverPromotion extends Command
{
    protected $signature = 'promotion:recover {--tenant=}';

    protected $description = 'Retry automatic annual fee returns. Timed refunds use deposits:process-refunds.';

    public function handle(): int
    {
        $selected = $this->option('tenant');
        if ($selected && ! Str::isUuid($selected)) {
            return self::INVALID;
        }
        foreach (Tenant::query()->when($selected, fn ($q) => $q->whereKey($selected))->orderBy('id')->cursor() as $tenant) {
            foreach (DB::table('paid_promotion_rebates')->where('tenant_id', $tenant->id)->where('source', 'AUTO')->where('status', 'PENDING')->orderBy('id')->lazyById(100) as $claim) {
                app(PaidPromotionRebate::class)->attempt($tenant->id, $claim->id);
            }

        }

        return self::SUCCESS;
    }
}
