<?php

namespace App\Http\Controllers\User;

use App\Application\CardProduct\CardProductCatalogQuery;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class CardsController extends Controller
{
    public function __invoke(TenantContext $context, CardProductCatalogQuery $query): Response
    {
        /** @var User|null $user */
        $user = Auth::guard('tenant_user')->user();

        return Inertia::render('user/Cards', $query->user($context->id(), $user?->id));
    }
}
