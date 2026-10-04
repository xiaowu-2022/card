<?php

namespace App\Application\Promotion;

use Illuminate\Support\Facades\DB;

final class CommissionDisplay
{
    public static function events(string $tenant, string $user, iterable $entries): array
    {
        return DB::table('manual_commission_adjustments as a')
            ->leftJoin('commission_adjustment_classifications as c', fn ($j) => $j->on('c.adjustment_id', '=', 'a.id')->on('c.tenant_id', '=', 'a.tenant_id')->on('c.user_id', '=', 'a.user_id'))
            ->where('a.tenant_id', $tenant)->where('a.user_id', $user)->whereIn('a.ledger_entry_id', $entries)
            ->get(['a.ledger_entry_id', 'c.kind'])->mapWithKeys(fn ($r) => [$r->ledger_entry_id => self::event($r->kind)])->all();
    }

    public static function event(?string $kind): string
    {
        return match ($kind) {
            'activation' => 'COMMISSION_EARN', 'annual' => 'PROMOTION_ANNUAL_COMMISSION', 'legacy' => 'LEGACY_COMMISSION', default => 'COMMISSION',
        };
    }
}
