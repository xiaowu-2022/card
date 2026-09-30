<?php

namespace App\Http\Controllers\Api;

use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;

final class AppReleaseController extends Controller
{
    public function __invoke(TenantContext $context)
    {
        $path = storage_path('app/app-releases/'.$context->id().'/android.json');
        abort_unless(is_file($path), 503, 'App release unavailable.');
        $release = json_decode(file_get_contents($path), true);
        abort_unless(is_array($release) && isset($release['versionCode'], $release['versionName'], $release['appId'], $release['path']), 503);
        abort_unless(preg_match('#^/app-releases/'.preg_quote($context->id(), '#').'/[a-f0-9]{64}\.apk$#D', $release['path']) && is_file(public_path($release['path'])), 503);

        return response()->json([
            'tenantSlug' => $context->tenant()->slug,
            'appId' => $release['appId'],
            'versionCode' => $release['versionCode'],
            'versionName' => $release['versionName'],
            'path' => $release['path'],
        ])->header('Cache-Control', 'private, no-store');
    }
}
