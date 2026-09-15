<?php

namespace App\Http\Controllers\User;

use App\Application\Card\CardholderTestMaterialsArchive;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SaveCardholderTestMaterialsRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class CardholderTestMaterialsController extends Controller
{
    public function store(SaveCardholderTestMaterialsRequest $request, TenantContext $context, CardholderTestMaterialsArchive $archive): RedirectResponse
    {
        $archive->save($context->id(), $request->user('tenant_user')->id, $request->validated(), $request->attributes->get('request_id'));

        return back()->with('success', 'Test materials saved securely.');
    }

    public function read(Request $request, TenantContext $context, string $product, CardholderTestMaterialsArchive $archive): JsonResponse
    {
        return response()->json($archive->read($context->id(), $request->user('tenant_user')->id, $product, $request->attributes->get('request_id')))
            ->header('Cache-Control', 'private, no-store, max-age=0')->header('Pragma', 'no-cache')->header('X-Content-Type-Options', 'nosniff');
    }
}
