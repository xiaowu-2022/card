<?php

namespace App\Http\Controllers\Platform;

use App\Application\Partners\LegacyStockReport;
use App\Application\Tenant\PlatformListFilters;
use App\Application\User\ExportPlatformUsers;
use App\Application\User\PlatformUserAncestors;
use App\Application\User\PlatformUserDescendantCounts;
use App\Application\User\PlatformUserQuery;
use App\Application\User\Snapshots\UserSnapshotReader;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

final class UserOperationsController extends Controller
{
    public function __invoke(Request $request, PlatformListFilters $lists, PlatformUserQuery $query, AuthorizationService $authorization): Response
    {
        $filters = $this->filters($request, $lists);
        $access = $this->access($request, $authorization);
        $snapshots = app(UserSnapshotReader::class);
        $at = $snapshots->cutoff($request);
        $props = $snapshots->read($at, fn () => [
            'users' => $this->users($query, $filters, $access),
            ...$this->historicalAccess($access, $at !== null),
            'companies' => $lists->companies(), 'filters' => $filters,
            'snapshotAt' => $filters['as_of'] ?? null,
        ], $filters['company'] ?? null);
        $props['snapshotEarliest'] = $snapshots->earliest($filters['company'] ?? null);

        return Inertia::render('platform/Users', $props);
    }

    public function subordinates(Request $request, string $tenant, string $user): JsonResponse
    {
        $data = $request->validate([
            'scope' => 'nullable|in:direct,all', 'search' => 'nullable|string|max:254',
            'page' => 'nullable|integer|min:1|max:100000',
        ]);
        DB::table('users')->where('tenant_id', $tenant)->where('id', $user)->firstOrFail();
        $query = DB::table('users as u')
            ->join('tenants as t', 't.id', '=', 'u.tenant_id')
            ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
            ->where('u.tenant_id', $tenant)->where('u.id', '<>', $user);
        if (($data['scope'] ?? 'all') === 'direct') {
            $query->whereExists(fn ($q) => $q->selectRaw('1')->from('promotion_members as m')
                ->join('promotion_members as parent', fn ($j) => $j->on('parent.id', '=', 'm.inviter_id')->on('parent.tenant_id', '=', 'm.tenant_id'))
                ->whereColumn('m.tenant_id', 'u.tenant_id')->whereColumn('m.user_id', 'u.id')->where('parent.user_id', $user));
        } else {
            $query->whereRaw('u.id IN (WITH RECURSIVE team AS (
                SELECT id, user_id, tenant_id FROM promotion_members WHERE tenant_id = ? AND user_id = ?
                UNION
                SELECT m.id, m.user_id, m.tenant_id FROM promotion_members m JOIN team ON m.inviter_id = team.id AND m.tenant_id = team.tenant_id
            ) SELECT user_id FROM team)', [$tenant, $user]);
        }
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            $pattern = '%'.addcslashes($search, '%_').'%';
            $query->where(fn ($q) => $q->where('u.account_id', 'like', $pattern)->orWhere('u.email', 'ilike', $pattern)->orWhere('p.display_name', 'ilike', $pattern));
        }
        $page = $query->orderByDesc('u.created_at')->orderBy('u.id')->paginate(20, [
            'u.id', 'u.tenant_id as companyId', 't.name as companyName', 'u.account_id as accountId',
            'p.display_name as displayName', 'u.email',
        ]);
        $counts = app(PlatformUserDescendantCounts::class)->forUsers($page->pluck('id')->all());
        $page->through(fn ($row) => (array) $row + ['descendantCount' => $counts[$row->id] ?? 0]);

        return response()->json($page)->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, PlatformListFilters $lists, AuthorizationService $authorization, ExportPlatformUsers $export)
    {
        $filters = $this->filters($request, $lists);
        $access = $this->access($request, $authorization);
        $snapshots = app(UserSnapshotReader::class);
        $at = $snapshots->cutoff($request);
        $csv = $snapshots->read($at, fn () => $export->execute($filters, $access), $filters['company'] ?? null);

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="users-'.now()->format('Ymd-His').'.csv"',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function filters(Request $request, PlatformListFilters $lists): array
    {
        return $lists->validated($request, [
            'status' => ['nullable', 'in:ACTIVE,SUSPENDED,DISABLED,DEPOSIT_REFUNDING'],
            'support' => ['nullable', 'in:Enabled,Disabled'], 'referrer' => ['nullable', 'string', 'max:120'],
            'as_of' => ['nullable', 'date_format:Y-m-d\\TH:i:s'],
        ]);
    }

    private function users(PlatformUserQuery $query, array $filters, array $access)
    {
        return $query->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null,
            $access['financialAccess'], $filters['support'] ?? null, null, 'Disabled', $filters['referrer'] ?? null,
            withRestrictions: $access['canManageRestrictions']);
    }

    private function historicalAccess(array $access, bool $historical): array
    {
        if ($historical) {
            foreach ($access as $key => $value) {
                if (str_starts_with($key, 'can') && ! in_array($key, ['canManageRestrictions'], true)) {
                    $access[$key] = false;
                }
            }
            // Restriction flags remain readable; editing is gated by readOnly in the UI.
        }

        return [...$access, 'readOnly' => $historical];
    }

    public function referrerCandidates(Request $request, PlatformListFilters $lists): JsonResponse
    {
        $filters = $lists->validated($request, ['selected' => ['nullable', 'string', 'max:120']]);
        $snapshots = app(UserSnapshotReader::class);
        $data = $snapshots->read($snapshots->cutoff($request), function () use ($filters) {
            $base = fn () => DB::table('users as u')
                ->leftJoin('user_profiles as p', fn ($j) => $j->on('p.user_id', '=', 'u.id')->on('p.tenant_id', '=', 'u.tenant_id'))
                ->when($filters['company'] ?? null, fn ($q, $company) => $q->where('u.tenant_id', $company));
            $columns = ['u.account_id', 'u.email', 'p.display_name'];
            $page = $base()->when($filters['search'] ?? null, function ($q, $search) {
                $pattern = '%'.addcslashes($search, '%_').'%';
                $q->where(fn ($q) => $q->where('u.account_id', 'like', $pattern)
                    ->orWhere('u.email', 'ilike', $pattern)->orWhere('p.display_name', 'ilike', $pattern));
            })->orderBy('u.account_id')->simplePaginate(20, $columns);
            $selected = empty($filters['selected']) ? null : $base()
                ->where(fn ($q) => $q->where('u.account_id', $filters['selected'])
                    ->orWhereRaw('LOWER(u.email) = ?', [mb_strtolower($filters['selected'])]))->first($columns);

            return ['items' => $page->items(), 'hasMore' => $page->hasMorePages(), 'selected' => $selected];
        }, $filters['company'] ?? null);

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenant, string $user, PlatformUserQuery $query, AuthorizationService $authorization): JsonResponse
    {
        $access = $this->access($request, $authorization);
        $snapshots = app(UserSnapshotReader::class);
        $at = $snapshots->cutoff($request);
        $data = $snapshots->read($at, function () use ($query, $tenant, $user, $access, $at) {
            $record = $query->paginate($tenant, null, null, $access['financialAccess'], null, $user, withRestrictions: $access['canManageRestrictions'])->items()[0] ?? null;
            abort_if($record === null, 404);
            $record['invitationCode'] = DB::table('promotion_members')->where('tenant_id', $tenant)->where('user_id', $user)->value('invitation_code');
            $record['promotionTeamCount'] = $at === null
                ? app(LegacyStockReport::class)->team($tenant, $user)->where('user_id', '<>', $user)->count()
                : (app(PlatformUserDescendantCounts::class)->forUsers([$user])[$user] ?? 0);
            $record['ancestors'] = app(PlatformUserAncestors::class)->read($tenant, $user);
            $record['partnerId'] = $access['canManagePartners'] && $at === null
                ? DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->where('enabled', true)->value('id') : null;

            return ['user' => $record, ...$this->historicalAccess($access, $at !== null),
                'snapshotAt' => $at?->setTimezone('Asia/Shanghai')->format('Y-m-d\\TH:i:s')];
        }, $tenant);

        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    private function access(Request $request, AuthorizationService $authorization): array
    {
        $allowed = fn (string $permission): bool => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission);
        $financialAccess = ['balances' => $allowed('wallet.read'), 'receipts' => $allowed('wallet_topups.read'), 'commission' => $allowed('ledger.read'), 'withdrawals' => $allowed('withdrawals.read'), 'funds' => $allowed('wallet.read') && $allowed('ledger.read') && $allowed('cards.read')];

        return [
            'financialAccess' => $financialAccess, 'canManageSupport' => $allowed('support.read') && $allowed('support.agents.manage'),
            'canRemark' => $allowed('support.read') && $allowed('support.send'),
            'canManageRestrictions' => $allowed('users.restrictions.manage'),
            'canManagePartners' => $allowed('partners.manage'),
            'canCreateUser' => $allowed('users.create'),
            // Cleanup UI entry points were retired after the user's completed cleanup.
            'canCleanup' => false,
            'canChangeInvitation' => $allowed('users.invitation.manage'),
            'canChangeReferrer' => $allowed('users.referrer.manage'),
            'canAdjustCommission' => $financialAccess['balances'] && $allowed('commissions.adjust'),
            'canViewKyc' => $allowed('kyc.read'),
            'canViewFunds' => $financialAccess['balances'] && $allowed('ledger.read'),
            'canViewTopups' => $allowed('wallet_topups.read'),
            'canAdjustWallet' => $financialAccess['balances'] && $allowed('wallet.adjust'),
        ];
    }
}
