<?php

namespace App\Http\Controllers\Api;

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class HomeController extends Controller
{
    public function summary(Request $request, TenantContext $context, UserWalletQuery $wallets, AssetOverviewQuery $assets)
    {
        $user = $request->attributes->get('consumer_user');
        $legacy = $wallets->get($context->id(), $user->id, false);
        $eligibility = $legacy['eligibility'];

        return response()->json([
            'assetOverview' => $assets->get($context->id(), $user->id, $legacy, false),
            'kycStatus' => $eligibility['kycStatus'],
            'wallet' => $eligibility['wallet'] ? ['status' => $eligibility['walletStatus']] : null,
        ]);
    }

    public function activity(Request $request, TenantContext $context, AssetOverviewQuery $assets)
    {
        return response()->json(['activity' => $assets->recentActivity($context->id(), $request->attributes->get('consumer_user')->id)]);
    }
}
