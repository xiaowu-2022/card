<?php

namespace App\Application\Promotion;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Reporting history only. Does not alter payment gates or historical awards. */
final class OrdinaryMemberQuery
{
    public function users(?string $tenant = null, ?CarbonImmutable $at = null): Builder
    {
        $cutoff = ($at ?? CarbonImmutable::now())->format('Y-m-d H:i:s.uP');
        // A refund changes money, not the fact that this user successfully funded a deposit.
        $funded = DB::table('ledger_entries as funding')
            ->join('ledger_postings as posting', fn ($j) => $j->on('posting.ledger_entry_id', '=', 'funding.id')->on('posting.tenant_id', '=', 'funding.tenant_id'))
            ->join('ledger_accounts as deposit', fn ($j) => $j->on('deposit.id', '=', 'posting.ledger_account_id')->on('deposit.tenant_id', '=', 'posting.tenant_id'))
            ->when($tenant, fn ($q) => $q->where('funding.tenant_id', $tenant))
            ->where('funding.event_type', 'SECURITY_DEPOSIT_FUND')->whereNotNull('funding.sealed_at')
            ->where('funding.asset_code', 'USDT')->where('funding.posted_at', '<=', $cutoff)
            ->where('deposit.asset_code', 'USDT')->where('deposit.account_type', 'USER_SECURITY_DEPOSIT')
            ->whereNotNull('deposit.user_id')->where('posting.delta', '>', 0)
            ->selectRaw('deposit.tenant_id,deposit.user_id,MAX(funding.posted_at) AS funded_at')
            ->groupBy('deposit.tenant_id', 'deposit.user_id');

        $paid = DB::table('paid_promotion_orders')
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->where('status', 'COMPLETED')->where('rank', '>', 0)->where('completed_at', '<=', $cutoff)
            ->selectRaw('tenant_id,user_id,completed_at AS became_agent_at');
        $manual = DB::table('manual_promotion_adjustments')
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant))
            ->where('effective_rank', '>', 0)->where('created_at', '<=', $cutoff)
            ->selectRaw('tenant_id,user_id,created_at AS became_agent_at');
        $agents = DB::query()->fromSub($paid->unionAll($manual), 'agent_history')
            ->selectRaw('tenant_id,user_id,MAX(became_agent_at) AS became_agent_at')->groupBy('tenant_id', 'user_id');

        // Current agents take precedence in callers. Equal timestamps favor the agent event;
        // an expired/downgraded agent must fund again after their latest agent grant.
        return DB::query()->fromSub($funded, 'funded')
            ->leftJoinSub($agents, 'agent', fn ($j) => $j->on('agent.tenant_id', '=', 'funded.tenant_id')->on('agent.user_id', '=', 'funded.user_id'))
            ->where(fn ($q) => $q->whereNull('agent.became_agent_at')->orWhereColumn('funded.funded_at', '>', 'agent.became_agent_at'))
            ->select('funded.tenant_id', 'funded.user_id');
    }
}
