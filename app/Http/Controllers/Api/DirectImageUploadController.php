<?php

namespace App\Http\Controllers\Api;

use App\Application\Media\DirectImageUploads;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class DirectImageUploadController extends Controller
{
    public function store(Request $request, TenantContext $context, DirectImageUploads $uploads)
    {
        $data = $request->validate(['purpose' => 'required|in:kyc,card,support', 'field' => 'required|in:front,back,support_image',
            'mime' => 'required|in:image/jpeg,image/png,image/webp', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited']);

        return response()->json($uploads->authorize($context->id(), $request->attributes->get('consumer_user')->id,
            $data['purpose'], $data['field'], $data['mime']))->header('Cache-Control', 'private, no-store');
    }

    public function complete(string $upload, Request $request, TenantContext $context, DirectImageUploads $uploads)
    {
        $uploads->complete($context->id(), $request->attributes->get('consumer_user')->id, $upload);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
