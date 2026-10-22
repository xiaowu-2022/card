<?php

namespace App\Application\Partners;

use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class PlatformPartnerQuery
{
    public function query(Request $request, ?string $tenant, bool $canReadAmounts): Builder
    {
        $partnerQuery = DB::table('partner_configurations as p')->where('p.enabled', true)->join('users as u', fn ($j) => $j->on('u.id', '=', 'p.user_id')->on('u.tenant_id', '=', 'p.tenant_id'))->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', '=', 'u.id')->on('profile.tenant_id', '=', 'u.tenant_id'))->join('tenants as t', 't.id', '=', 'p.tenant_id')->when($tenant, fn ($q) => $q->where('p.tenant_id', $tenant))->when($request->filled('referrer'), fn ($q) => $q->whereExists(fn ($m) => $m->selectRaw('1')->from('promotion_members as m')->join('promotion_members as parent', fn ($j) => $j->on('parent.id', '=', 'm.inviter_id')->on('parent.tenant_id', '=', 'm.tenant_id'))->join('users as referrer', fn ($j) => $j->on('referrer.id', '=', 'parent.user_id')->on('referrer.tenant_id', '=', 'parent.tenant_id'))->whereColumn('m.tenant_id', 'p.tenant_id')->whereColumn('m.user_id', 'p.user_id')->where('referrer.account_id', $request->query('referrer'))));
        if ($request->query('sort') === 'registered_at') {
            $partnerQuery->orderBy('u.created_at', $request->query('direction', 'asc'));
        } elseif ($request->query('sort') === 'advance') {
            abort_unless($canReadAmounts, 403);
            $advance = DB::table('partner_journal_entries as sorting_journal')
                ->whereColumn('sorting_journal.tenant_id', 'p.tenant_id')
                ->whereColumn('sorting_journal.partner_id', 'p.id')
                ->where('sorting_journal.kind', 'ADVANCE')
                ->selectRaw('COALESCE(SUM(CASE WHEN sorting_journal.reverses_id IS NULL THEN sorting_journal.amount ELSE -sorting_journal.amount END), 0)');
            $partnerQuery->orderByRaw('(('.$advance->toSql().') + (SELECT COALESCE(SUM(CASE WHEN uaj.reverses_id IS NULL THEN uaj.amount ELSE -uaj.amount END),0) FROM user_advance_entries uaj WHERE uaj.tenant_id=p.tenant_id AND uaj.user_id=p.user_id)) '.$request->query('direction', 'asc'), $advance->getBindings());
        }

        return $partnerQuery->orderBy('u.account_id')->orderBy('p.id');
    }

    public const COLUMNS = ['p.id', 'p.tenant_id', 'p.user_id', 't.name as company_name', 'p.enabled', 'p.updated_at', 'p.share_percent', 'u.created_at as registered_at', 'u.account_id', 'profile.display_name', 'u.email'];
}
