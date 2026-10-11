<?php

namespace App\Application\Cleanup;

use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Storage;

final class CleanupBackup
{
    public function write(string $batch, #[\SensitiveParameter] array $snapshot): array
    {
        $path = 'data-cleanup/'.$batch.'.enc';
        $contents = Crypt::encryptString(base64_encode(gzencode(json_encode($snapshot, JSON_THROW_ON_ERROR), 6)));
        $disk = Storage::disk('private');
        try {
            $verified = $disk->put($path, $contents, ['visibility' => 'private']) && $disk->exists($path)
                && hash_equals(hash('sha256', $contents), hash('sha256', $disk->get($path)));
        } catch (\Throwable) {
            $verified = false;
        }
        if (! $verified) {
            throw new DomainException('CLEANUP_BACKUP_FAILED', 'Encrypted cleanup backup could not be verified.', 409);
        }
        if (config('filesystems.disks.private.driver') === 'local') {
            chmod($disk->path($path), 0600);
        }

        return ['path' => $path, 'hash' => hash('sha256', $contents)];
    }
}
