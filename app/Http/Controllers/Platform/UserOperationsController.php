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
        $filters = $lists->validated($request, ['status' => ['nullable', 'in:ACTIVE,SUSPENDED,DISABLED'], 'support' => ['nullable', 'in:Enabled,Disabled'], 'partner' => ['nullable', 'in:Enabled,Disabled']]);
        $access = $this->access($request, $authorization);
        $financialAccess = $access['financialAccess'];

        return Inertia::render('platform/Users', [
            'users' => $query->paginate($filters['company'] ?? null, $filters['search'] ?? null, $filters['status'] ?? null, $financialAccess, $filters['support'] ?? null, null, $filters['partner'] ?? null),
            ...$access,
            'companies' => $lists->companies(), 'filters' => $filters,
        ]);
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
            'canCreateUser' => $allowed('users.create'),
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
