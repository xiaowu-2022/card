<?php

namespace App\Http\Controllers\User;

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportUnread;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SupportController extends Controller
{
    public function show(Request $request, TenantContext $context, SupportChatQuery $query): Response
    {
        $request->validate(['before' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);

        return Inertia::render('user/Support', ['chat' => $query->user($context->id(), $request->user('tenant_user')->id, $request->integer('before'))])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function read(Request $request, TenantContext $context, SupportUnread $unread): Response
    {
        $data = $request->validate(['through' => 'required|integer|min:1|max:2147483647']);
        $unread->read($context->id(), $request->user('tenant_user')->id, $data['through']);

        return response()->noContent();
    }

    public function store(SendSupportMessageRequest $request, TenantContext $context, SendSupportMessageAction $action): RedirectResponse
    {
        $action->user($context->id(), $request->user('tenant_user')->id, $request->validated('request_id'), $request->validated('support_message') ?? '', $request->file('support_image'));

        return redirect('/support');
    }

    public function image(Request $request, TenantContext $context, SupportChatQuery $query, SupportImageStorage $images, string $message): Response
    {
        $image = $query->image($context->id(), $request->user('tenant_user')->id, $message, false);

        return response($images->read($image['path']), 200, [
            'Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename=support-image', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
