<?php

namespace App\Http\Controllers\Platform;

use App\Application\Media\OssSettings;
use App\Domain\Media\OssConfiguration;
use App\Domain\Media\StoredImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

final class OssSettingsController extends Controller
{
    public function show()
    {
        return Inertia::render('platform/OssSettings', [
            'configurations' => OssConfiguration::latest()->get(['id', 'region', 'bucket', 'endpoint', 'public_url', 'verified_at', 'created_at']),
            'activeId' => DB::table('media_storage_settings')->where('id', 1)->value('active_configuration_id'),
            'counts' => StoredImage::selectRaw('state, count(*) as total')->groupBy('state')->pluck('total', 'state'),
        ]);
    }

    public function save(Request $request, OssSettings $settings)
    {
        $settings->save($request->only(['region', 'bucket', 'endpoint', 'public_url', 'access_key_id', 'access_key_secret']), $request->user('platform_admin'));

        return back()->with('success', 'OSS configuration saved. Test it before enabling.');
    }

    public function check(OssConfiguration $configuration, Request $request, OssSettings $settings)
    {
        $settings->check($configuration, $request->user('platform_admin'));

        return back()->with('success', 'OSS connection verified.');
    }

    public function activate(OssConfiguration $configuration, Request $request, OssSettings $settings)
    {
        $settings->activate($configuration, $request->user('platform_admin'));

        return back()->with('success', 'OSS enabled for new uploads.');
    }
}
