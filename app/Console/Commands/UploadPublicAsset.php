<?php

namespace App\Console\Commands;

use App\Domain\Media\OssConfiguration;
use App\Infrastructure\Storage\OssImages;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** CLI worker for the bounded public asset publisher. No credentials in arguments/output. */
final class UploadPublicAsset extends Command
{
    protected $signature = 'assets:upload-one {configuration} {file} {key} {mime} {sha256}';

    protected $description = 'Internal checksum-verified public asset upload worker';

    protected $hidden = true;

    public function handle(OssImages $oss): int
    {
        try {
            $file = realpath($this->argument('file'));
            $roots = [public_path('images/'), public_path('data/card-geography/'), public_path('build/assets/'), base_path('mobile/uni-app/src/static/icons/'), base_path('dist/clients/')];
            if (! $file || (! array_any($roots, fn ($root) => str_starts_with($file, $root)) && $file !== public_path('favicon.ico')) || ! str_starts_with($this->argument('key'), 'assets/') || str_contains($this->argument('key'), '..')) {
                return self::FAILURE;
            }
            $config = OssConfiguration::findOrFail($this->argument('configuration'));
            $bytes = file_get_contents($file);
            $hash = $this->argument('sha256');
            if (! hash_equals($hash, hash('sha256', $bytes))) {
                return self::FAILURE;
            }
            $records = json_decode(DB::table('media_storage_settings')->where('id', 1)->value('public_assets') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $staged = $records['@staging/'.hash('sha256', $config->id.'/'.$this->argument('key'))] ?? null;
            if ($staged && hash_equals($hash, $staged['sha256']) && $staged['configuration_id'] === $config->id && $staged['object_key'] === $this->argument('key')) {
                return self::SUCCESS;
            }
            $verified = false;
            for ($attempt = 0; $attempt < 3; $attempt++) {
                try {
                    $oss->put($config, $this->argument('key'), $bytes, $this->argument('mime'));
                    $verified = hash_equals($hash, hash('sha256', $oss->get($config, $this->argument('key')))) && hash_equals($hash, hash_file('sha256', $file));
                    if ($verified) {
                        break;
                    }
                } catch (\Throwable) {
                    // Retry only the identical immutable public object; never a business operation.
                }
            }
            if (! $verified) {
                return self::FAILURE;
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            return self::FAILURE;
        }
    }
}
