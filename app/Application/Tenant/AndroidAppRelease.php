<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class AndroidAppRelease
{
    public const DOWNLOAD_URL = 'http://zb33333.com/specpay.apk';

    public static function rules(): array
    {
        return [
            'versionCode' => ['required', 'integer', 'min:1', 'max:2100000000'],
            'versionName' => ['required', 'string', 'max:100', 'regex:/^\d+\.\d+\.\d+(?:[.-][a-zA-Z0-9]+)*$/D'],
            'appId' => ['required', 'string', 'max:100', 'regex:/^__UNI__[A-Z0-9]+$/D'],
        ];
    }

    public function current(string $tenantId): ?array
    {
        $metadata = DB::table('tenant_android_releases')->where('tenant_id', $tenantId)->value('metadata');
        if ($metadata !== null) {
            return json_decode($metadata, true, flags: JSON_THROW_ON_ERROR);
        }
        // Old CLI publications remain readable without migration or writes on GET.
        $path = storage_path('app/app-releases/'.$tenantId.'/android.json');
        if (! is_file($path)) {
            return null;
        }
        $legacy = json_decode(File::get($path), true);

        return is_array($legacy) ? $legacy : null;
    }

    public function revision(?array $release): string
    {
        return hash('sha256', json_encode($release, JSON_THROW_ON_ERROR));
    }

    public function available(string $tenantId, ?array $release): bool
    {
        return $release !== null && ! Validator::make($release, self::rules())->fails()
            && isset($release['path']) && is_string($release['path'])
            && preg_match('#^/app-releases/'.preg_quote($tenantId, '#').'/[a-f0-9]{64}\.apk$#D', $release['path'])
            && is_file(public_path($release['path']));
    }

    public function settings(Tenant $tenant): array
    {
        $release = $this->current($tenant->id);

        return ['current' => $release === null ? null : array_intersect_key($release, array_flip(['appId', 'versionCode', 'versionName'])),
            'revision' => $this->revision($release), 'available' => $this->available($tenant->id, $release),
            'downloadUrl' => self::DOWNLOAD_URL];
    }

    public function publish(Tenant $tenant, string $apk, array $data, ?AdminUser $actor = null, ?string $requestId = null, ?string $expectedRevision = null): void
    {
        if ($actor) {
            app(CompanyConfigurationAuthority::class)->assert($actor);
        }
        $metadata = Validator::make($data, self::rules())->validate();
        $metadata['versionCode'] = (int) $metadata['versionCode'];
        // Keep the CLI's archive signature check; version/signing metadata is supplied
        // by the publisher, not claimed as parsed or cryptographically verified.
        if (! is_file($apk) || filesize($apk) > 250 * 1024 * 1024 || file_get_contents($apk, false, null, 0, 2) !== 'PK') {
            throw ValidationException::withMessages(['apk' => 'Upload a valid APK file up to 250 MB.']);
        }
        $hash = hash_file('sha256', $apk);
        $metadata['path'] = '/app-releases/'.$tenant->id.'/'.$hash.'.apk';
        $target = public_path($metadata['path']);
        File::ensureDirectoryExists(dirname($target));
        // Content-addressed artifacts are installed before advertising the release.
        // Failed/stale publications can leave an unreferenced artifact, never a broken pointer.
        if (! is_file($target) || hash_file('sha256', $target) !== $hash) {
            $temporary = $target.'.'.Str::uuid().'.tmp';
            try {
                if (! File::copy($apk, $temporary) || hash_file('sha256', $temporary) !== $hash || ! rename($temporary, $target)) {
                    throw new \RuntimeException('APK checksum verification failed.');
                }
            } finally {
                File::delete($temporary);
            }
        }
        DB::transaction(function () use ($tenant, $metadata, $actor, $requestId, $expectedRevision): void {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($actor) {
                app(CompanyConfigurationAuthority::class)->assert($actor);
            }
            $old = $this->current($tenant->id);
            // Identical retries do not create a second publication/audit entry.
            if ($old == $metadata) {
                return;
            }
            if ($expectedRevision !== null && ! hash_equals($this->revision($old), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => 'The Android release changed. Reload this editor and try again.']);
            }
            if ($old && (($old['appId'] ?? null) !== $metadata['appId'] || $metadata['versionCode'] <= ($old['versionCode'] ?? 0))) {
                throw ValidationException::withMessages(['versionCode' => 'Keep the DCloud AppID unchanged and increase the version code for each new APK.']);
            }
            DB::table('tenant_android_releases')->updateOrInsert(['tenant_id' => $tenant->id], [
                'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR),
                'created_at' => DB::table('tenant_android_releases')->where('tenant_id', $tenant->id)->value('created_at') ?? now(),
                'updated_at' => now(),
            ]);
            app(AuditLogger::class)->record($tenant->id, $actor ? 'ADMIN' : 'SYSTEM', $actor?->id,
                'ANDROID_RELEASE_PUBLISHED', 'tenant_android_release', $tenant->id, $old, $metadata, $requestId);
        });
    }
}
