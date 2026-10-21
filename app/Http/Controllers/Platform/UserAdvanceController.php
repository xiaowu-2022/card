<?php

namespace App\Http\Controllers\Platform;

use App\Application\Partners\UserAdvances;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Brick\Math\BigDecimal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class UserAdvanceController extends Controller
{
    public function show(Request $request, string $tenant, string $user, UserAdvances $advances)
    {
        $data = $request->validate(['page' => 'nullable|integer|min:1|max:100000']);

        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($tenant, $user, $advances, $data, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            User::where('tenant_id', $tenant)->findOrFail($user);
            $own = DB::table('user_advance_entries as j')->where('j.tenant_id', $tenant)->where('j.user_id', $user)
                ->select('j.id', 'j.amount', 'j.business_date', 'j.note', 'j.reverses_id', 'j.created_at')
                ->selectRaw("NULL::uuid AS partner_id, 'user'::text AS source, EXISTS(SELECT 1 FROM user_advance_entries r WHERE r.reverses_id=j.id) AS reversed,
                    EXISTS(SELECT 1 FROM wallet_topup_orders o WHERE o.tenant_id=j.tenant_id AND o.user_advance_journal_id=j.id)
                    OR EXISTS(SELECT 1 FROM asset_deposit_orders o WHERE o.tenant_id=j.tenant_id AND o.user_advance_journal_id=j.id)
                    OR EXISTS(SELECT 1 FROM deposit_receipt_type_changes c WHERE c.tenant_id=j.tenant_id AND c.after_user_journal_id=j.id) AS order_backed");
            $legacy = DB::table('partner_journal_entries as j')->join('partner_configurations as p', fn ($j) => $j->on('p.id', 'j.partner_id')->on('p.tenant_id', 'j.tenant_id'))
                ->where('j.tenant_id', $tenant)->where('p.user_id', $user)->where('j.kind', 'ADVANCE')
                ->select('j.id', 'j.amount', 'j.business_date', 'j.note', 'j.reverses_id', 'j.created_at')
                ->selectRaw("p.id AS partner_id, 'partner'::text AS source, EXISTS(SELECT 1 FROM partner_journal_entries r WHERE r.reverses_id=j.id) AS reversed, false AS order_backed");
            $page = DB::query()->fromSub($own->unionAll($legacy), 'history')->orderByDesc('created_at')->orderBy('id')
                ->paginate(20, ['*'], 'page', $data['page'] ?? 1);

            return response()->json(['amount' => (string) BigDecimal::of($advances->ownTotals($tenant, [$user])[$user] ?? '0')->toScale(8),
                'items' => $page->items(), 'page' => $page->currentPage(), 'pages' => $page->lastPage(), 'total' => $page->total()])->header('Cache-Control', 'private, no-store');
        });
    }

    public function store(Request $request, string $tenant, string $user, UserAdvances $advances)
    {
        $entry = $advances->record($request->user('platform_admin'), $tenant, $user, $request->only(['amount', 'business_date', 'note', 'request_id', 'reverses_id', 'confirmed']));

        return response()->json(['id' => $entry->id])->header('Cache-Control', 'private, no-store');
    }
}
