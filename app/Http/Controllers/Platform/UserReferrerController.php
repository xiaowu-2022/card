<?php

namespace App\Http\Controllers\Platform;

use App\Application\Promotion\ChangeReferrer;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class UserReferrerController extends Controller
{
    public function show(Request $request, string $tenant, string $user, ChangeReferrer $action)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:255']]);
        $account = User::where('tenant_id', $tenant)->findOrFail($user);
        $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();
        $descendants = $action->descendants($tenant, $member->id);
        $identity = fn ($id) => DB::table('promotion_members as m')->join('users as u', 'u.id', '=', 'm.user_id')->where('m.tenant_id', $tenant)->where('u.tenant_id', $tenant)->where('m.id', $id)->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', 'u.id')->on('p.tenant_id', 'u.tenant_id'))->first(['m.id', 'u.account_id', 'u.email', 'p.display_name']);
        $candidates = [];
        if (trim($data['search'] ?? '') !== '') {
            $candidates = $this->candidateQuery($tenant, $member, $descendants, trim($data['search']))->limit(20)->get();
        }

        return Inertia::render('platform/UserReferrer', [
            'account' => ['id' => $user, 'companyId' => $tenant, 'companyName' => Tenant::findOrFail($tenant)->name, 'accountId' => $account->account_id, 'email' => $account->email],
            'current' => $identity($member->inviter_id), 'revision' => (int) $member->referrer_revision, 'descendants' => count($descendants),
            'search' => $data['search'] ?? '', 'candidates' => $candidates,
            'history' => DB::table('referrer_changes as r')->leftJoin('promotion_members as old', 'old.id', '=', 'r.old_inviter_id')->leftJoin('users as ou', 'ou.id', '=', 'old.user_id')
                ->join('promotion_members as new', 'new.id', '=', 'r.new_inviter_id')->join('users as nu', 'nu.id', '=', 'new.user_id')
                ->where('r.tenant_id', $tenant)->where('r.user_id', $user)->orderByDesc('r.revision')->paginate(20, ['r.id', 'r.reason', 'r.actor_name', 'r.created_at', 'r.descendants', 'ou.account_id as old_account', 'nu.account_id as new_account'])->withQueryString(),
        ]);
    }

    public function candidates(Request $request, string $tenant, string $user, ChangeReferrer $action)
    {
        $data = $request->validate(['search' => 'nullable|string|max:255', 'page' => 'nullable|integer|min:1|max:100000']);
        $outer = DB::transactionLevel();

        return DB::transaction(function () use ($request, $tenant, $user, $action, $data, $outer) {
            if ($outer === 0) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
            }
            User::where('tenant_id', $tenant)->findOrFail($user);
            $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->firstOrFail();
            $rows = $this->candidateQuery($tenant, $member, $action->descendants($tenant, $member->id), trim($data['search'] ?? ''))
                ->simplePaginate(20, ['*'], 'page', $request->integer('page', 1));

            return response()->json(['items' => $rows->items(), 'page' => $rows->currentPage(), 'hasMore' => $rows->hasMorePages()])
                ->header('Cache-Control', 'private, no-store');
        });
    }

    private function candidateQuery(string $tenant, PromotionMember $member, array $descendants, string $search): Builder
    {
        $query = DB::table('promotion_members as m')->join('users as u', fn ($j) => $j->on('u.id', 'm.user_id')->on('u.tenant_id', 'm.tenant_id'))
            ->leftJoin('user_profiles as profile', fn ($j) => $j->on('profile.user_id', 'u.id')->on('profile.tenant_id', 'u.tenant_id'))
            ->where('m.tenant_id', $tenant)->where('u.status', 'ACTIVE')
            ->whereNotIn('m.id', array_filter([$member->id, $member->inviter_id, ...$descendants]));
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '\\%_').'%';
            $compact = str_replace('-', '', $search);
            $query->where(function ($q) use ($pattern, $compact) {
                $q->where('u.account_id', 'ilike', $pattern)->orWhere('u.email', 'ilike', $pattern)->orWhere('profile.display_name', 'ilike', $pattern)
                    ->orWhereRaw('u.id::text ILIKE ?', [$pattern])->orWhereRaw('m.id::text ILIKE ?', [$pattern]);
                if ($compact !== '') {
                    $compactPattern = '%'.addcslashes($compact, '\\%_').'%';
                    $q->orWhereRaw("replace(u.id::text, '-', '') ILIKE ?", [$compactPattern])->orWhereRaw("replace(m.id::text, '-', '') ILIKE ?", [$compactPattern]);
                }
            });
        }

        return $query->orderBy('u.account_id')->orderBy('m.id')->select('m.id', 'u.account_id', 'u.email', 'profile.display_name');
    }

    public function update(Request $request, string $tenant, string $user, ChangeReferrer $action)
    {
        $data = $request->validate(['new_inviter_id' => ['required', 'uuid'], 'old_inviter_id' => ['nullable', 'uuid'], 'revision' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'], 'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted']]);
        $action->execute($tenant, $user, $request->user('platform_admin'), $data);

        return back()->with('success', 'Referrer updated.');
    }
}
