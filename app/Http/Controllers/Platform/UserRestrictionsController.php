<?php

namespace App\Http\Controllers\Platform;

use App\Application\User\UpdateUserOperationRestrictions;
use App\Application\User\UserOperationRestrictions;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class UserRestrictionsController extends Controller
{
    public function show(string $tenant, string $user)
    {
        $account = User::where('tenant_id', $tenant)->whereKey($user)->firstOrFail();

        return response()->json(UserOperationRestrictions::values($account) + ['revision' => (int) $account->operation_restrictions_revision])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $tenant, string $user, UpdateUserOperationRestrictions $action)
    {
        return response()->json($action->execute($tenant, $user, $request->user('platform_admin'),
            $request->only([...UserOperationRestrictions::FIELDS, 'revision', 'confirmed', 'request_id'])))->header('Cache-Control', 'private, no-store');
    }
}
