<?php

namespace App\Application\Partners;

use Illuminate\Support\Facades\DB;

final class PartnerAnnualSubsidies
{
    /** Own signed subsidies only; never inherit another partner's entries. */
    public function totals(string $tenant, array $partners): array
    {
        if ($partners === []) {
            return [];
        }

        return DB::table('partner_journal_entries')->where('tenant_id', $tenant)
            ->whereIn('partner_id', $partners)->where('kind', 'ANNUAL_SUBSIDY')
            ->select('partner_id')->selectRaw('SUM(CASE WHEN reverses_id IS NULL THEN amount ELSE -amount END)::text AS amount')
            ->groupBy('partner_id')->pluck('amount', 'partner_id')->all();
    }
}
