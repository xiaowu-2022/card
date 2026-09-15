<?php

namespace App\Http\Controllers\User;

use App\Application\Card\UserCardCenterQuery;
use App\Application\CardProduct\CardProductCatalogQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class CardsController extends Controller
{
    public function index(TenantContext $context, UserCardCenterQuery $query): Response
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/Cards', $query->get($context->id(), $user->id));
    }

    public function demo(TenantContext $context, CardProductCatalogQuery $query): Response
    {
        return Inertia::render('user/Cards', $query->user($context->id(), null) + [
            'providerAvailable' => true,
            'kycApproved' => false,
            'availableBalance' => null,
            'walletAsset' => 'USDT',
            'cardholder' => ['state' => 'setup', 'id' => null, 'requestId' => null, 'productId' => null, 'canSync' => false, 'safeReason' => null, 'submittedAt' => null, 'syncedAt' => null],
            'issueOrders' => [],
            'cards' => [],
            'demo' => true,
        ]);
    }
}
