<?php

namespace App\Console\Commands;

use App\Application\Media\ImageReferences;
use App\Application\Media\ImageStorage;
use App\Domain\Media\StoredImage;
use Illuminate\Console\Command;

final class RecoverImages extends Command
{
    protected $signature = 'images:recover {--limit=100}';

    protected $description = 'Retry staged image cleanup only; never replay OCR or business operations';

    public function handle(ImageStorage $images, ImageReferences $refs): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $records = StoredImage::where(function ($q) {
            $q->where(fn ($q) => $q->where('state', 'cleanup_pending')->where('cleanup_after', '<=', now()))
                ->orWhere(fn ($q) => $q->whereIn('state', ['uploading', 'ready'])->whereNotNull('cleanup_after')->where('cleanup_after', '<=', now()));
        })->orderBy('updated_at')->limit($limit)->get();
        foreach ($records as $record) {
            try {
                if ($refs->contains($record->tenant_id, $record->source_disk, $record->source_key)) {
                    $record->update(['cleanup_after' => null]);

                    continue;
                }
                $images->discard($record->source_disk, $record->source_key);
            } catch (\Throwable) {
                $this->warn('Image cleanup deferred: '.$record->id);
            }
        }

        return self::SUCCESS;
    }
}
