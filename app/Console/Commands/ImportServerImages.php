<?php

namespace App\Console\Commands;

use App\Application\Media\ImageReplicas;
use App\Application\Media\ServerImages;
use App\Domain\Media\StoredImage;
use Illuminate\Console\Command;

final class ImportServerImages extends Command
{
    protected $signature = 'images:import-server';

    protected $description = 'Verify local originals and import missing encrypted server copies without contacting OSS';

    public function handle(ServerImages $local, ImageReplicas $replicas): int
    {
        $saved = $ready = $missing = 0;
        StoredImage::where('state', 'ready')->orderBy('id')->chunk(100, function ($rows) use ($local, $replicas, &$saved, &$ready, &$missing) {
            foreach ($rows as $image) {
                try {
                    if ($image->backup_key) {
                        try {
                            $replicas->read($image);
                            $ready++;

                            continue;
                        } catch (\Throwable) {
                        }
                    }
                    $bytes = $local->read($image);
                    $replicas->save($image, $bytes);
                    $saved++;
                } catch (\Throwable) {
                    $missing++;
                    $this->warn('Missing or invalid server original: '.$image->id);
                }
            }
        });
        $this->info("Imported {$saved}; already verified {$ready}; missing {$missing}.");

        return $missing ? self::FAILURE : self::SUCCESS;
    }
}
