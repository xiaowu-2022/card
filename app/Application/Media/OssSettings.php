<?php

namespace App\Application\Media;

use App\Application\Promotion\PaidPromotionRules;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Media\OssConfiguration;
use App\Infrastructure\Storage\OssImages;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OssSettings
{
    public function prepare(array $data, AdminUser $actor): OssConfiguration
    {
        app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
        $data = Validator::make($data, [
            'region' => ['required', 'string', 'regex:/^[a-z][a-z0-9-]{2,40}$/D'],
            'bucket' => ['required', 'string', 'regex:/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/D'],
            'endpoint' => ['required', 'string', 'max:255'], 'public_url' => ['required', 'string', 'max:255'],
            'access_key_id' => ['required', 'string', 'max:200'], 'access_key_secret' => ['required', 'string', 'max:300'],
        ])->validate();
        $data['endpoint'] = rtrim(trim($data['endpoint']), '/');
        $data['public_url'] = rtrim($data['public_url'], '/');
        if (! preg_match('#^https://[a-zA-Z0-9][a-zA-Z0-9.-]+[a-zA-Z0-9]$#D', $data['public_url']) || filter_var(parse_url($data['public_url'], PHP_URL_HOST), FILTER_VALIDATE_IP)) {
            throw ValidationException::withMessages(['public_url' => 'Use an HTTPS public image domain without a path.']);
        }

        $this->validateRegion($data['region'], $data['endpoint'], $data['public_url']);

        // A custom API endpoint must be the explicitly configured public image domain (OSS CNAME).
        if (! preg_match('#^https://oss-'.preg_quote($data['region'], '#').'(-internal)?\.aliyuncs\.com$#D', $data['endpoint'])
            && $data['endpoint'] !== $data['public_url']) {
            throw ValidationException::withMessages(['endpoint' => 'Use the regional OSS endpoint or the configured image domain.']);
        }

        return new OssConfiguration(collect($data)->except(['access_key_id', 'access_key_secret'])->all() + [
            'credentials' => ['access_key_id' => $data['access_key_id'], 'access_key_secret' => $data['access_key_secret']], 'created_by' => $actor->id,
        ]);
    }

    public function save(array $data, AdminUser $actor): OssConfiguration
    {
        $config = $this->prepare($data, $actor);

        return DB::transaction(function () use ($config, $actor) {
            app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
            $config->save();
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'OSS_CONFIGURATION_CREATED', 'oss_configuration', $config->id, null, ['bucket' => $config->bucket, 'region' => $config->region]);

            return $config;
        });
    }

    public function check(OssConfiguration $config, AdminUser $actor): void
    {
        app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
        $this->validateRegion($config->region, $config->endpoint, $config->public_url);
        $oss = ServerImages::enabled() ? app(OssImages::class)->forConnectionTest() : app(OssImages::class);
        $key = 'connection-tests/'.Str::uuid().'.png';
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        $ok = false;
        try {
            if (! app()->environment('testing')) {
                $ips = gethostbynamel(parse_url($config->public_url, PHP_URL_HOST));
                if (! $ips || array_any($ips, fn ($ip) => ! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE))) {
                    throw new \RuntimeException;
                }
            }
            $oss->put($config, $key, $bytes, 'image/png');
            if (! hash_equals(hash('sha256', $bytes), hash('sha256', $oss->get($config, $key)))) {
                throw new \RuntimeException;
            }
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()->get($oss->url($config, $key));
            if (! $response->successful() || ! hash_equals(hash('sha256', $bytes), hash('sha256', $response->body()))) {
                throw new \RuntimeException;
            }
            $ok = true;
        } catch (\Throwable) {
            $ok = false;
        } finally {
            try {
                $oss->delete($config, $key);
            } catch (\Throwable) {
                $ok = false;
            }
        }
        if (! $ok) {
            throw new DomainException('OSS_TEST_FAILED', 'OSS test failed. Check credentials, endpoint, public access and image domain.', 422);
        }
        if (! $config->exists) {
            return;
        }
        DB::transaction(function () use ($actor, $config) {
            app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
            $config->update(['verified_at' => now()]);
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'OSS_CONNECTION_VERIFIED', 'oss_configuration', $config->id);
        });
    }

    private function validateRegion(string $region, string ...$urls): void
    {
        foreach ($urls as $url) {
            $host = parse_url($url, PHP_URL_HOST) ?? '';
            if (preg_match('/^(?:[a-z0-9-]+\.)?oss-([a-z0-9-]+?)(?:-internal)?\.aliyuncs\.com$/D', $host, $matches)
                && $region !== $matches[1]) {
                throw ValidationException::withMessages(['region' => 'OSS region must match the endpoint. For Beijing, use cn-beijing.']);
            }
        }
    }

    public function activate(OssConfiguration $config, AdminUser $actor): void
    {
        DB::transaction(function () use ($config, $actor) {
            DB::table('media_storage_settings')->where('id', 1)->lockForUpdate()->first();
            app(PaidPromotionRules::class)->platform($actor, 'storage.manage');
            $this->validateRegion($config->region, $config->endpoint, $config->public_url);
            DB::table('media_storage_settings')->where('id', 1)->update(['active_configuration_id' => $config->id, 'updated_at' => now()]);
            app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'OSS_CONFIGURATION_ENABLED', 'oss_configuration', $config->id);
        });
    }
}
