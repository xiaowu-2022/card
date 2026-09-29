<?php

namespace App\Http\Controllers\Platform;

use App\Application\Media\OssSettings;
use App\Application\Media\ServerImages;
use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Media\OssConfiguration;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

final class OssSettingsController extends Controller
{
    private function current(): ?OssConfiguration
    {
        $id = DB::table('media_storage_settings')->where('id', 1)->value('active_configuration_id');

        return $id ? OssConfiguration::findOrFail($id) : OssConfiguration::latest()->first();
    }

    public function show()
    {
        return Inertia::render('platform/OssSettings', [
            'serverStorage' => ServerImages::enabled(),
            'driverRevision' => (int) DB::table('media_storage_settings')->where('id', 1)->value('driver_revision'),
            'configuration' => $this->current()?->only(['id', 'region', 'bucket', 'endpoint', 'public_url']),
        ]);
    }

    private function input(Request $request): array
    {
        $request->validate(['expected_id' => ['nullable', 'uuid']]);
        $current = $this->current();
        if (($request->input('expected_id') ?: null) !== $current?->id) {
            throw ValidationException::withMessages(['form' => 'Storage configuration changed. Refresh before saving.']);
        }
        $data = $request->only(['region', 'bucket', 'endpoint', 'public_url', 'access_key_id', 'access_key_secret']);
        foreach (['access_key_id', 'access_key_secret'] as $key) {
            if (blank($data[$key] ?? null)) {
                $data[$key] = $current?->credentials[$key] ?? '';
            }
        }

        return $data;
    }

    public function save(Request $request, OssSettings $settings)
    {
        DB::transaction(function () use ($request, $settings) {
            DB::table('media_storage_settings')->where('id', 1)->lockForUpdate()->first();
            $data = $this->input($request);
            $current = $this->current();
            $prepared = $settings->prepare($data, $request->user('platform_admin'));
            if ($current && $current->only(['region', 'bucket', 'endpoint', 'public_url', 'credentials']) === $prepared->only(['region', 'bucket', 'endpoint', 'public_url', 'credentials'])) {
                $settings->activate($current, $request->user('platform_admin'));
            } else {
                $settings->activate($settings->save($data, $request->user('platform_admin')), $request->user('platform_admin'));
            }
        });

        return back()->with('success', 'OSS configuration saved.');
    }

    public function storageDriver(Request $request, OssSettings $settings)
    {
        $data = $request->validate([
            'storage_driver' => ['required', 'in:server,oss'],
            'expected_revision' => ['required', 'integer', 'min:0'],
        ]);
        DB::transaction(function () use ($request, $settings, $data) {
            $row = DB::table('media_storage_settings')->where('id', 1)->lockForUpdate()->first();
            $actor = $request->user('platform_admin');
            app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
            if ((int) $row->driver_revision !== (int) $data['expected_revision']) {
                throw ValidationException::withMessages(['storage_driver' => 'Storage configuration changed. Refresh before saving.']);
            }
            if ($data['storage_driver'] === 'oss') {
                $config = $row->active_configuration_id ? OssConfiguration::find($row->active_configuration_id) : null;
                if (! $config) {
                    throw ValidationException::withMessages(['storage_driver' => 'Save a complete OSS configuration before selecting OSS.']);
                }
                $settings->prepare($config->only(['region', 'bucket', 'endpoint', 'public_url']) + $config->credentials, $actor);
            }
            $before = ServerImages::enabled() ? 'server' : 'oss';
            DB::table('media_storage_settings')->where('id', 1)->update([
                'storage_driver' => $data['storage_driver'],
                'driver_revision' => $row->driver_revision + 1,
                'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id,
                'IMAGE_STORAGE_DRIVER_CHANGED', 'media_storage_settings', null,
                ['storage_driver' => $before], ['storage_driver' => $data['storage_driver']]);
        });

        return back()->with('success', 'Storage mode saved.');
    }

    public function test(Request $request, OssSettings $settings)
    {
        $settings->check($settings->prepare($this->input($request), $request->user('platform_admin')), $request->user('platform_admin'));

        return back()->with('success', 'OSS connection verified.');
    }

    public function check(OssConfiguration $configuration, Request $request, OssSettings $settings)
    {
        $settings->check($configuration, $request->user('platform_admin'));

        return back()->with('success', 'OSS connection verified.');
    }

    public function activate(OssConfiguration $configuration, Request $request, OssSettings $settings)
    {
        $settings->activate($configuration, $request->user('platform_admin'));

        return back()->with('success', 'OSS configuration saved.');
    }
}
