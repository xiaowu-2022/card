<?php

namespace App\Console\Commands;

use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

final class PublishAppRelease extends Command
{
    protected $signature = 'app:publish-android {tenant : Company slug} {apk : Signed APK path} {--code= : APK versionCode} {--release-version= : APK versionName} {--appid= : DCloud appid from manifest.json}';

    protected $description = 'Publish a company Android release for mandatory App updates';

    public function handle(): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
        $code = filter_var($this->option('code'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2100000000]]);
        $version = (string) $this->option('release-version');
        $appId = (string) $this->option('appid');
        $apk = (string) $this->argument('apk');
        if (! $code || ! preg_match('/^\d+\.\d+\.\d+(?:[.-][a-zA-Z0-9]+)*$/D', $version) || ! preg_match('/^__UNI__[A-Z0-9]+$/D', $appId) || ! is_file($apk) || file_get_contents($apk, false, null, 0, 2) !== 'PK') {
            $this->error('Provide a signed APK and its exact positive versionCode, versionName and DCloud appid.');

            return self::FAILURE;
        }
        $directory = storage_path('app/app-releases/'.$tenant->id);
        File::ensureDirectoryExists($directory);
        $lock = fopen($directory.'/publish.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $manifest = $directory.'/android.json';
            $old = is_file($manifest) ? json_decode(file_get_contents($manifest), true) : null;
            $hash = hash_file('sha256', $apk);
            $path = '/app-releases/'.$tenant->id.'/'.$hash.'.apk';
            if ($old && ($old['appId'] !== $appId || $code < $old['versionCode'] || ($code === $old['versionCode'] && ($old['path'] !== $path || $old['versionName'] !== $version)))) {
                $this->error('Keep the appid unchanged and increment versionCode for each new APK.');

                return self::FAILURE;
            }
            $target = public_path($path);
            File::ensureDirectoryExists(dirname($target));
            if (! is_file($target) || hash_file('sha256', $target) !== $hash) {
                File::copy($apk, $target.'.tmp');
                if (hash_file('sha256', $target.'.tmp') !== $hash) {
                    throw new \RuntimeException('APK checksum mismatch.');
                }
                rename($target.'.tmp', $target);
            }
            File::put($manifest.'.tmp', json_encode(['appId' => $appId, 'versionCode' => $code, 'versionName' => $version, 'path' => $path], JSON_THROW_ON_ERROR));
            rename($manifest.'.tmp', $manifest);
            $this->info('Published '.$version.' ('.$code.') for '.$tenant->slug.'.');

            return self::SUCCESS;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
