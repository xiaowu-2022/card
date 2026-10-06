<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenant\AndroidAppRelease;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;

final class AppReleaseController extends Controller
{
    public function __invoke(TenantContext $context, AndroidAppRelease $releases)
    {
        $release = $releases->current($context->id());
        abort_unless($releases->available($context->id(), $release), 503, 'App release unavailable.');

        return response()->json([
            'tenantSlug' => $context->tenant()->slug,
            'appId' => $release['appId'],
            'versionCode' => $release['versionCode'],
            'versionName' => $release['versionName'],
            'path' => $release['path'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
