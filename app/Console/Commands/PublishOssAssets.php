<?php

namespace App\Console\Commands;

use App\Application\Media\PublicAssets;
use Illuminate\Console\Command;

final class PublishOssAssets extends Command
{
    protected $signature = 'assets:publish-oss {--execute} {--web} {--h5=}';

    protected $description = 'Publish repository public artwork/icons to OSS with checksum verification and retained originals';

    public function handle(PublicAssets $assets): int
    {
        if (! $this->option('execute')) {
            $this->line(json_encode(['mode' => 'preview', 'files' => count($assets->sources((bool) $this->option('web'), $this->option('h5')))], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }
        try {
            $this->line(json_encode($assets->publish((bool) $this->option('web'), $this->option('h5'), fn ($path) => $this->line('Verified: '.$path)), JSON_THROW_ON_ERROR));
            if ($this->option('web') && $this->option('h5')) {
                $directory = realpath(base_path($this->option('h5')));
                $manifest = $assets->manifest(true);
                $html = file_get_contents($directory.'/index.html');
                $html = preg_replace_callback('/(src|href)="\.\/(assets\/[^"<>]+)"/', function ($match) use ($manifest) {
                    $url = $manifest['/h5/'.$match[2]] ?? throw new \RuntimeException('Missing H5 asset');

                    return $match[1].'="'.htmlspecialchars($url, ENT_QUOTES).'"';
                }, $html);
                $json = json_encode($assets->manifest(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR);
                $favicon = htmlspecialchars($manifest['/favicon.ico'] ?? '/favicon.ico', ENT_QUOTES);
                $html = str_replace('<head>', '<head><link rel="icon" href="'.$favicon.'"><script>window.__PUBLIC_ASSETS__='.$json.';</script>', $html);
                file_put_contents($directory.'/index.oss.html', $html);
                $this->info('Prepared index.oss.html; publish it as the H5 index after verification.');
            }

            return self::SUCCESS;
        } catch (\Throwable) {
            $this->error('Asset publication failed. Verified mappings and original files are retained; check OSS configuration and retry.');

            return self::FAILURE;
        }
    }
}
