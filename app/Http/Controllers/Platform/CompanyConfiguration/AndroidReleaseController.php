<?php

namespace App\Http\Controllers\Platform\CompanyConfiguration;

use App\Application\Tenant\AndroidAppRelease;
use App\Domain\Tenant\Models\Tenant;
use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class AndroidReleaseController extends Controller
{
    public function __invoke(Tenant $tenant, Request $request, AndroidAppRelease $releases): RedirectResponse
    {
        $data = $request->validate(array_replace(AndroidAppRelease::rules(), [
            'androidDownloadUrl' => array_diff(AndroidAppRelease::rules()['androidDownloadUrl'], ['sometimes']),
            'iosDistributionUrl' => array_merge(['required'], array_diff(AndroidAppRelease::rules()['iosDistributionUrl'], ['sometimes', 'nullable'])),
        ]) + [
            'apk' => ['prohibited'],
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['accepted'],
        ]);
        $releases->publish($tenant, $data,
            $request->user('platform_admin'), $request->attributes->get('request_id'), $data['revision']);

        return back()->with('success', 'App release published.');
    }
}
