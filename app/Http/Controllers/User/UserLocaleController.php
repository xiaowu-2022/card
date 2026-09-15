<?php

namespace App\Http\Controllers\User;

use App\Application\User\UpdateUserLocaleAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Models\User;
use App\Http\Middleware\ResolveUserLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class UserLocaleController
{
    public function __invoke(Request $request, TenantContext $context, UpdateUserLocaleAction $update): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'max:16'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'],
        ]);
        $user = Auth::guard('tenant_user')->user();
        $update->execute($context->tenant(), $user instanceof User ? $user : null, $data['locale']);

        return response()->json(['locale' => $data['locale']])->cookie(
            ResolveUserLocale::cookieName($context->id()), $data['locale'], 525600,
            '/', '', $request->isSecure(), true, false, 'lax',
        );
    }
}
