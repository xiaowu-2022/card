<?php

namespace App\Application\Tenant;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class AndroidAppRelease
{
    public const DOWNLOAD_URL = 'http://zb33333.com/specpay.apk';

    public static function rules(): array
    {
        return [
            'androidDownloadUrl' => ['sometimes', 'required', 'string', 'max:2048', 'url:http,https', 'not_regex:/[\s\\\\]/', 'regex:#^https?://[^/@]+(?:[/?\#]|$)#i'],
            'iosDistributionUrl' => ['sometimes', 'nullable', 'string', 'max:2048', 'url:http,https', 'not_regex:/[\s\\\\]/', 'regex:#^https?://[^/@]+(?:[/?\#]|$)#i'],
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

    public function available(?array $release): bool
    {
        return $release !== null && ! Validator::make($release, self::rules())->fails();
    }

    public function compatibilityPath(string $tenantId, array $release): string
    {
        // Installed static-download clients still validate this legacy field's shape.
        // It is a compatibility identifier, not a stored/downloadable APK or checksum.
        $oldPath = $release['path'] ?? null;
        if (is_string($oldPath) && preg_match('#^/app-releases/'.preg_quote($tenantId, '#').'/[a-f0-9]{64}\.apk$#D', $oldPath)) {
            return $oldPath;
        }
        $identity = [$release['appId'], (int) $release['versionCode'], $release['versionName']];

        return '/app-releases/'.$tenantId.'/'.hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR)).'.apk';
    }

    public function settings(Tenant $tenant): array
    {
        $release = $this->current($tenant->id);

        return ['current' => $release === null ? null : array_intersect_key($release, array_flip(['appId', 'versionCode', 'versionName'])),
            'revision' => $this->revision($release), 'available' => $this->available($release),
            ...$this->destinations($release)];
    }

    public function destinations(?array $release): array
    {
        return [
            'downloadUrl' => $release['androidDownloadUrl'] ?? self::DOWNLOAD_URL,
            'androidDownloadUrl' => $release['androidDownloadUrl'] ?? self::DOWNLOAD_URL,
            'iosDistributionUrl' => $release['iosDistributionUrl'] ?? null,
        ];
    }

    public function publish(Tenant $tenant, array $data, ?AdminUser $actor = null, ?string $requestId = null, ?string $expectedRevision = null): void
    {
        if ($actor) {
            app(CompanyConfigurationAuthority::class)->assert($actor);
        }
        $metadata = Validator::make($data, self::rules())->validate();
        $metadata['versionCode'] = (int) $metadata['versionCode'];
        DB::transaction(function () use ($tenant, $metadata, $actor, $requestId, $expectedRevision): void {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($actor) {
                app(CompanyConfigurationAuthority::class)->assert($actor);
            }
            $old = $this->current($tenant->id);
            // Legacy CLI callers preserve configured destinations rather than resetting them.
            $metadata += [
                'androidDownloadUrl' => $old['androidDownloadUrl'] ?? self::DOWNLOAD_URL,
                'iosDistributionUrl' => $old['iosDistributionUrl'] ?? null,
            ];
            // Identical retries do not create a second publication/audit entry.
            if ($old !== null && array_intersect_key($old + ['androidDownloadUrl' => self::DOWNLOAD_URL, 'iosDistributionUrl' => null], $metadata) == $metadata) {
                return;
            }
            if ($expectedRevision !== null && ! hash_equals($this->revision($old), $expectedRevision)) {
                throw ValidationException::withMessages(['revision' => 'The app release changed. Reload this editor and try again.']);
            }
            if ($old && ($old['appId'] ?? null) !== $metadata['appId']) {
                throw ValidationException::withMessages(['appId' => 'Keep the DCloud AppID unchanged.']);
            }
            // Platform edits may correct metadata or destinations without a new binary.
            // The legacy CLI remains a new-release publisher.
            if (! $actor && $old && $metadata['versionCode'] <= ($old['versionCode'] ?? 0)) {
                throw ValidationException::withMessages(['versionCode' => 'Keep the DCloud AppID unchanged and increase the version code for each new release.']);
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
