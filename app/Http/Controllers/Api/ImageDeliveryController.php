<?php

namespace App\Http\Controllers\Api;

use App\Application\Media\ImageStorage;
use App\Domain\Media\StoredImage;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class ImageDeliveryController extends Controller
{
    public function __invoke(string $image, Request $request, ImageStorage $images)
    {
        // A short-lived, host-bound capability generated only from owned server mappings.
        abort_unless($request->hasValidSignature(), 403);
        $record = StoredImage::whereKey($image)->where('state', 'ready')->firstOrFail();
        $profile = $request->query('profile', 'original');
        abort_unless(in_array($profile, ['original', 'brand', 'preview', 'document', 'poster'], true), 422);

        abort_unless(in_array($request->query('delivery'), [null, 'replica'], true), 422);

        return $images->displayResponse($record->source_disk, $record->source_key, $profile, $record->codec, $request->query('delivery') === 'replica');
    }
}
