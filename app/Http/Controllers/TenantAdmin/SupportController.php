<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Domain\Tenant\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class SupportController extends Controller
{
    public function index(Request $request, TenantContext $context, SupportChatQuery $query): Response
    {
        $request->validate(['page' => ['nullable', 'integer', 'min:1', 'max:100000']]);

        return Inertia::render('tenant-admin/SupportInbox', ['inbox' => $query->inbox($context->id(), $request->user('tenant_admin')->id, $request->integer('page', 1))])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, TenantContext $context, SupportChatQuery $query, string $conversation): Response
    {
        $request->validate(['before' => ['nullable', 'integer', 'min:0', 'max:2147483647']]);

        return Inertia::render('tenant-admin/SupportChat', ['chat' => $query->admin($context->id(), $request->user('tenant_admin')->id, $conversation, $request->integer('before'))])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function store(SendSupportMessageRequest $request, TenantContext $context, SendSupportMessageAction $action, string $conversation): RedirectResponse
    {
        $action->admin($context->id(), $request->user('tenant_admin')->id, $conversation, $request->validated('request_id'), $request->validated('support_message') ?? '', $request->file('support_image'));

        return redirect('/admin/support/'.$conversation);
    }

    public function image(Request $request, TenantContext $context, SupportChatQuery $query, SupportImageStorage $images, string $message): Response
    {
        $image = $query->image($context->id(), $request->user('tenant_admin')->id, $message, true);

        return response($images->read($image['path']), 200, [
            'Content-Type' => $image['mime'], 'Cache-Control' => 'private, no-store',
            'Content-Disposition' => 'inline; filename=support-image', 'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'no-referrer', 'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
