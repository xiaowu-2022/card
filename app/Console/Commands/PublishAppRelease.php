<?php

namespace App\Console\Commands;

use App\Application\Tenant\AndroidAppRelease;
use App\Domain\Tenant\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class PublishAppRelease extends Command
{
    protected $signature = 'app:publish-android {tenant : Company slug} {apk : Signed APK path} {--code= : APK versionCode} {--release-version= : APK versionName} {--appid= : DCloud appid from manifest.json}';

    protected $description = 'Publish a company Android release for mandatory App updates';

    public function handle(AndroidAppRelease $releases): int
    {
        $tenant = Tenant::where('slug', $this->argument('tenant'))->firstOrFail();
        try {
            $releases->publish($tenant, $this->argument('apk'), [
                'versionCode' => $this->option('code'),
                'versionName' => $this->option('release-version'),
                'appId' => $this->option('appid'),
            ]);
        } catch (ValidationException $error) {
            foreach ($error->errors() as $messages) {
                $this->error(implode(' ', $messages));
            }

            return self::FAILURE;
        }
        $this->info('Published '.$this->option('release-version').' ('.$this->option('code').') for '.$tenant->slug.'.');

        return self::SUCCESS;
    }
}
