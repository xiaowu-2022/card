<?php

namespace App\Http\Controllers\Api;

use App\Application\Media\DirectImageUploads;
use App\Application\Media\DirectKycUploads;
use App\Application\Media\ServerImages;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

final class DirectImageUploadController extends Controller
{
    public function store(Request $request, TenantContext $context, DirectImageUploads $uploads)
    {
        $data = $request->validate(['purpose' => 'required|in:kyc,card,support', 'field' => 'required|in:front,back,support_image',
            'mime' => 'required|in:image/jpeg,image/png,image/webp', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited', 'recognize_front' => 'sometimes|boolean']);

        $user = $request->attributes->get('consumer_user')->id;
        $preview = (bool) ($data['recognize_front'] ?? false);
        abort_if($preview && ($data['purpose'] !== 'kyc' || $data['field'] !== 'front'), 422);
        $ticket = $data['purpose'] === 'kyc' && ! $preview && ! ServerImages::enabled()
            ? app(DirectKycUploads::class)->authorize($context->id(), $user, $data['field'], $data['mime'])
            : $uploads->authorize($context->id(), $user, $data['purpose'], $data['field'], $data['mime']);

        return response()->json($ticket)->header('Cache-Control', 'private, no-store');
    }

    public function backup(string $upload, Request $request, TenantContext $context, DirectImageUploads $uploads)
    {
        $request->validate(['file' => 'required|file|mimes:jpg,jpeg,png,webp|max:10240']);
        $uploads->backup($context->id(), $request->attributes->get('consumer_user')->id, $upload, $request->file('file')->getContent());

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }

    public function complete(string $upload, Request $request, TenantContext $context, DirectImageUploads $uploads)
    {
        $uploads->complete($context->id(), $request->attributes->get('consumer_user')->id, $upload);

        return response()->noContent()->header('Cache-Control', 'private, no-store');
    }
}
