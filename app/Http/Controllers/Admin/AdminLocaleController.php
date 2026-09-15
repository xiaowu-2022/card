<?php

namespace App\Http\Controllers\Admin;

use App\Http\Middleware\ResolveAdminLocale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class AdminLocaleController
{
    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', Rule::in(ResolveAdminLocale::LOCALES)],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'admin_id' => ['prohibited'],
        ]);

        // A display preference only: no identities, memberships, permissions or tenant settings are changed.
        return response()->json(['locale' => $data['locale']])->cookie(
            ResolveAdminLocale::cookieName($request), $data['locale'], 525600,
            $request->attributes->get('locale_surface') === 'platform' ? '/platform' : '/admin',
            '', $request->isSecure(), true, false, 'lax',
        );
    }
}
