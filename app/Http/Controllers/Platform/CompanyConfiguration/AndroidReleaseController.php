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
        $data = $request->validate(AndroidAppRelease::rules() + [
            'apk' => ['required', 'file', 'max:256000', 'extensions:apk'],
            'revision' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
            'confirmed' => ['accepted'],
        ]);
        $releases->publish($tenant, $request->file('apk')->getPathname(), $data,
            $request->user('platform_admin'), $request->attributes->get('request_id'), $data['revision']);

        return back()->with('success', 'Android release published.');
    }
}
