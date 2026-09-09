<?php

namespace App\Http\Controllers\User;

use App\Application\Wallet\UserWalletQuery;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class DashboardController extends Controller
{
    public function __invoke(TenantContext $context, KycStatusService $kycStatuses, UserWalletQuery $wallets): Response
    {
        /** @var User|null $user */
        $user = Auth::guard('tenant_user')->user();

        $wallet = $user ? $wallets->get($context->id(), $user->id)['eligibility']['wallet'] : null;

        return Inertia::render('user/Dashboard', [
            'account' => $user ? [
                'displayName' => $user->profile?->display_name,
                'status' => $user->status->value,
                'verifiedChannel' => $user->email_verified_at ? 'Email' : 'Phone',
            ] : null,
            'kycStatus' => $user ? $kycStatuses->forUser($context->id(), $user->id)->value : 'NOT_SUBMITTED',
            'walletStatus' => $wallet['status'] ?? null,
        ]);
    }
}
