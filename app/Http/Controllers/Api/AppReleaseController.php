<?php

namespace App\Http\Controllers\Api;

use App\Application\Tenant\AndroidAppRelease;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;

final class AppReleaseController extends Controller
{
    public function debug(TenantContext $context, AndroidAppRelease $releases)
    {
        $release = $releases->current($context->id());

        return response()->json([
            'tenantId' => $context->id(),
            'tenantSlug' => $context->tenant()->slug,
            'enabled' => ($release['debugEnabled'] ?? false) === true,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function __invoke(TenantContext $context, AndroidAppRelease $releases)
    {
        $release = $releases->current($context->id());
        abort_unless($releases->available($release), 503, 'App release unavailable.');

        return response()->json([
            'tenantSlug' => $context->tenant()->slug,
            'appId' => $release['appId'],
            'versionCode' => (int) $release['versionCode'],
            'versionName' => $release['versionName'],
            'path' => $releases->compatibilityPath($context->id(), $release),
            ...$releases->destinations($release),
        ])->header('Cache-Control', 'private, no-store');
    }
}
