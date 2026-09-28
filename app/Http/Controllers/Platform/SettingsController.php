<?php

namespace App\Http\Controllers\Platform;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Services\AuthorizationService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class SettingsController extends Controller
{
    public function __invoke(Request $request, AuthorizationService $authorization)
    {
        $admin = $request->user('platform_admin');
        if (! $admin) {
            return redirect()->guest('/platform/login');
        }
        abort_unless($admin->status === AdminUserStatus::Active, 403);
        foreach (['tenant.manage' => 'assets', 'storage.manage' => 'oss'] as $permission => $tab) {
            if ($authorization->allows($admin, ScopeType::Platform, null, $permission)) {
                return redirect('/platform/settings/'.$tab);
            }
        }
        abort(403);
    }
}
