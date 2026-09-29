<?php

namespace App\Application\Media;

use App\Infrastructure\Storage\OssImages;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

final class PublicAssets
{
    public function manifest(bool $includeBuilds = false): array
    {
        if (ServerImages::enabled()) {
            return [];
        }
        $records = json_decode(DB::table('media_storage_settings')->where('id', 1)->value('public_assets') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $config = app(ImageStorage::class)->active();
        if (! $config) {
            return [];
        }
        $result = [];
        foreach ($records as $path => $record) {
            if (str_starts_with($path, '@staging/')) {
                continue;
            }
            if (! $includeBuilds && (str_starts_with($path, '/build/') || str_starts_with($path, '/h5/'))) {
                continue;
            }
            $url = app(OssImages::class)->url($config, $record['object_key']);
            $profile = str_contains($path, '/promotion/') || str_contains($path, '/growth/poster-') ? 'poster' : 'preview';
            $process = ImagePresentation::process($profile, $record['mime']);
            $result[$path] = $process ? $url.'?'.http_build_query(['x-oss-process' => $process], '', '&', PHP_QUERY_RFC3986) : $url;
        }

        return $result;
    }

    /** Only repository-owned public artwork/icons; never crawl storage, env files or user archives. */
    public function sources(bool $web = false, ?string $h5 = null): array
    {
        $files = [];
        foreach ([public_path('images') => '/images/', base_path('mobile/uni-app/src/static/icons') => '/icons/', public_path('data/card-geography') => '/data/card-geography/'] as $directory => $prefix) {
            foreach (File::allFiles($directory) as $file) {
                if ($file->isLink() || ! in_array(strtolower($file->getExtension()), ['png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'json'], true)) {
                    continue;
                }
                $files[$prefix.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
            }
        }
        if (is_file(public_path('favicon.ico'))) {
            $files['/favicon.ico'] = public_path('favicon.ico');
        }
        if ($web) {
            $directories = [public_path('build/assets') => '/build/assets/'];
            if ($h5) {
                $root = realpath(base_path($h5));
                if (! $root || ! str_starts_with($root, base_path('dist/clients/')) || ! is_file($root.'/index.html')) {
                    throw new \InvalidArgumentException('Use a built H5 directory under dist/clients');
                }
                $directories[$root.'/assets'] = '/h5/assets/';
                $directories[$root.'/static'] = '/h5/static/';
            }
            foreach ($directories as $directory => $prefix) {
                foreach (File::allFiles($directory) as $file) {
                    if (! $file->isLink() && in_array(strtolower($file->getExtension()), ['js', 'css', 'json', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'ico', 'woff', 'woff2', 'ttf', 'wasm'], true)) {
                        $files[$prefix.str_replace('\\', '/', $file->getRelativePathname())] = $file->getPathname();
                    }
                }
            }
        }
        ksort($files);

        return $files;
    }

    public function publish(bool $web = false, ?string $h5 = null, ?\Closure $progress = null): array
    {
        $config = app(ImageStorage::class)->active();
        if (! $config) {
            throw new \RuntimeException('Active OSS required');
        }
        if (! DB::selectOne('select pg_try_advisory_lock(20260929, 160000) as acquired')->acquired) {
            throw new \RuntimeException('Asset publication already running');
        }
        $summary = ['uploaded' => 0, 'unchanged' => 0];
        try {
            $records = json_decode(DB::table('media_storage_settings')->where('id', 1)->value('public_assets') ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            $sources = $this->sources($web, $h5);
            $uploads = [];
            $resolved = [];
            foreach ($sources as $path => $file) {
                $bytes = file_get_contents($file);
                $hash = hash('sha256', $bytes);
                $build = str_starts_with($path, '/build/assets/') || str_starts_with($path, '/h5/assets/');
                $key = $build ? 'assets/web'.$path : 'assets/'.$hash.'/'.basename($file);
                if ($build && ($records[$path]['object_key'] ?? null) === $key && $records[$path]['sha256'] !== $hash) {
                    throw new \RuntimeException('Build filenames must contain content hashes');
                }
                $stagedKey = '@staging/'.hash('sha256', $config->id.'/'.$key);
                $existing = $records[$stagedKey] ?? $records[$path] ?? null;
                if (($existing['object_key'] ?? null) === $key && ($existing['sha256'] ?? null) === $hash && $existing['configuration_id'] === $config->id) {
                    $resolved[$path] = $existing;
                    $summary['unchanged']++;

                    continue;
                }
                $mime = match (strtolower(pathinfo($file, PATHINFO_EXTENSION))) {
                    'js' => 'application/javascript', 'css' => 'text/css', 'json' => 'application/json', 'wasm' => 'application/wasm',
                    'svg' => 'image/svg+xml', 'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf',
                    default => (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes),
                };
                $uploads[$path] = ['file' => $file, 'configuration_id' => $config->id, 'object_key' => $key, 'sha256' => $hash, 'mime' => $mime];
            }
            if (app()->environment('testing')) {
                foreach ($uploads as $path => $record) {
                    $oss = app(OssImages::class);
                    $bytes = file_get_contents($record['file']);
                    $oss->put($config, $record['object_key'], $bytes, $record['mime']);
                    if (! hash_equals($record['sha256'], hash('sha256', $oss->get($config, $record['object_key'])))) {
                        throw new \RuntimeException('Asset checksum mismatch');
                    }
                }
            } else {
                $queue = [];
                $objects = [];
                foreach ($uploads as $path => $record) {
                    if (! isset($objects[$record['object_key']])) {
                        $queue[$path] = $record;
                        $objects[$record['object_key']] = true;
                    }
                }
                $running = [];
                while ($queue || $running) {
                    while ($queue && count($running) < 6) {
                        $path = array_key_first($queue);
                        $record = $queue[$path];
                        unset($queue[$path]);
                        $process = new Process([PHP_BINARY, base_path('artisan'), 'assets:upload-one', $config->id, $record['file'], $record['object_key'], $record['mime'], $record['sha256']], base_path(), timeout: 600);
                        $process->start();
                        $running[$path] = $process;
                    }
                    foreach ($running as $path => $process) {
                        if ($process->isRunning()) {
                            continue;
                        }
                        unset($running[$path]);
                        if (! $process->isSuccessful()) {
                            if ($progress) {
                                $progress('FAILED '.$path);
                            }
                            foreach ($running as $active) {
                                $active->stop();
                            }
                            throw new \RuntimeException('Asset upload failed');
                        }
                        if ($progress) {
                            $progress($path);
                        }
                        // Persist artwork progress; compiled resources switch atomically below.
                        $record = $uploads[$path];
                        unset($record['file']);
                        $recordKey = $web ? '@staging/'.hash('sha256', $config->id.'/'.$record['object_key']) : $path;
                        $records[$recordKey] = $record;
                        DB::table('media_storage_settings')->where('id', 1)->update(['public_assets' => json_encode($records, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
                    }
                    if ($running) {
                        usleep(100000);
                    }
                }
            }
            foreach ($uploads as $path => $record) {
                unset($record['file']);
                $records[$path] = $record;
                $summary['uploaded']++;
            }
            foreach ($sources as $path => $file) {
                $expected = $uploads[$path]['sha256'] ?? $resolved[$path]['sha256'];
                if (! hash_equals($expected, hash_file('sha256', $file))) {
                    throw new \RuntimeException('Source changed during publication');
                }
            }
            $records = array_merge($records, $resolved);
            $records = array_filter($records, fn ($path) => ! str_starts_with($path, '@staging/'), ARRAY_FILTER_USE_KEY);
            if ($web || app()->environment('testing')) {
                DB::table('media_storage_settings')->where('id', 1)->update(['public_assets' => json_encode($records, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            }
        } finally {
            DB::select('select pg_advisory_unlock(20260929, 160000)');
        }

        return $summary;
    }
}
