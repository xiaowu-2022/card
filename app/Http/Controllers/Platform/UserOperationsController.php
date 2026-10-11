<?php

namespace App\Http\Controllers\Platform;

use App\Application\Partners\LegacyStockReport;
use App\Application\Tenant\PlatformListFilters;
use App\Application\User\PlatformUserAncestors;
use App\Application\User\PlatformUserQuery;
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
        $filters = $lists->validated($request, ['status' => ['nullable', 'in:ACTIVE,SUSPENDED,DISABLED'], 'support' => ['nullable', 'in:Enabled,Disabled'], 'partner' => ['nullable', 'in:Enabled,Disabled'], 'referrer' => ['nullable', 'string', 'max:120']]);
        $access = $this->access($request, $authorization);
        $financialAccess = $access['financialAccess'];

        return Inertia::render('platform/Users', [
            'users' => $query->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null, $financialAccess, $filters['support'] ?? null, null, $filters['partner'] ?? null, $filters['referrer'] ?? null),
            ...$access,
            'companies' => $lists->companies(), 'filters' => $filters,
        ]);
    }

    public function referrerCandidates(Request $request, PlatformListFilters $lists): JsonResponse
    {
        $filters = $lists->validated($request, ['selected' => ['nullable', 'string', 'max:120']]);
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

        return response()->json(['items' => $page->items(), 'hasMore' => $page->hasMorePages(), 'selected' => $selected])
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $tenant, string $user, PlatformUserQuery $query, AuthorizationService $authorization): JsonResponse
    {
        $access = $this->access($request, $authorization);
        $record = $query->paginate($tenant, null, null, $access['financialAccess'], null, $user)->items()[0] ?? null;
        abort_if($record === null, 404);
        $record['invitationCode'] = DB::table('promotion_members')->where('tenant_id', $tenant)->where('user_id', $user)->value('invitation_code');
        $record['promotionTeamCount'] = app(LegacyStockReport::class)->team($tenant, $user)->where('user_id', '<>', $user)->count();
        $record['ancestors'] = app(PlatformUserAncestors::class)->read($tenant, $user);
        $record['partnerId'] = $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, 'partners.manage')
            ? DB::table('partner_configurations')->where('tenant_id', $tenant)->where('user_id', $user)->where('enabled', true)->value('id')
            : null;

        return response()->json(['user' => $record, ...$access])->header('Cache-Control', 'private, no-store');
    }

    private function access(Request $request, AuthorizationService $authorization): array
    {
        $allowed = fn (string $permission): bool => $authorization->allows($request->user('platform_admin'), ScopeType::Platform, null, $permission);
        $financialAccess = ['balances' => $allowed('wallet.read'), 'receipts' => $allowed('wallet_topups.read'), 'commission' => $allowed('ledger.read'), 'withdrawals' => $allowed('withdrawals.read')];

        return [
            'financialAccess' => $financialAccess, 'canManageSupport' => $allowed('support.read') && $allowed('support.agents.manage'),
            'canRemark' => $allowed('support.read') && $allowed('support.send'),
            'canManageRestrictions' => $allowed('users.restrictions.manage'),
            'canManagePartners' => $allowed('partners.manage'),
            'canCreateUser' => $allowed('users.create'),
            'canCleanup' => $allowed('users.cleanup'),
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
