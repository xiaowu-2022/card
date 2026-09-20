<?php

namespace App\Application\Promotion;

use Illuminate\Support\Facades\DB;

final class PromotionRanks
{
    /** Configured and historical ranks; gaps are valid, zero denotes ordinary members. */
    public static function forTenant(string $tenant): array
    {
        $query = DB::table('paid_promotion_levels')->where('tenant_id', $tenant)->select('rank');
        foreach (['paid_promotion_cycles' => 'rank', 'paid_promotion_events' => 'source_rank', 'paid_promotion_shares' => 'rank'] as $table => $column) {
            $query->union(DB::table($table)->where('tenant_id', $tenant)->selectRaw($column.' AS rank'));
        }

        return collect([0])->merge($query->pluck('rank'))->map(fn ($rank) => (int) $rank)->unique()->sort()->values()->all();
    }
}
