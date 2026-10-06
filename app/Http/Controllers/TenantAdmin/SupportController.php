<?php

namespace App\Http\Controllers\TenantAdmin;

use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportAccess;
use App\Application\Support\SupportBot;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportProfiles;
use App\Application\Support\SupportQuickReplies;
use App\Domain\Support\Models\SupportConversation;
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

        return Inertia::render('tenant-admin/SupportInbox', ['inbox' => $query->inbox($context->id(), $request->user('tenant_admin')->id, $request->integer('page', 1)), 'supportName' => $request->user('tenant_admin')->support_name])->toResponse($request)->header('Cache-Control', 'private, no-store');
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

    public function profile(Request $request, TenantContext $context, SupportProfiles $profiles): RedirectResponse
    {
        $data = $request->validate(['support_name' => ['nullable', 'string', 'max:30']]);
        $id = $request->user('tenant_admin')->id;
        $profiles->update($id, $id, $data['support_name'] ?? null, $context->id(), true);

        return back()->with('success', 'Support name saved.');
    }

    public function image(Request $request, TenantContext $context, SupportChatQuery $query, SupportImageStorage $images, string $message): Response
    {
        $image = $query->image($context->id(), $request->user('tenant_admin')->id, $message, true);

        return $images->response($image['path']);
    }

    public function finish(Request $request, TenantContext $context, string $conversation, SupportBot $bot)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'revision' => 'required|integer|min:1']);
        $row = SupportConversation::where('tenant_id', $context->id())->findOrFail($conversation);
        $bot->finish($context->id(), $row->user_id, $request->user('tenant_admin')->id, $data['request_id'], $data['revision'], false);

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function quick(Request $request, TenantContext $context, SupportQuickReplies $replies)
    {
        $actor = $request->user('tenant_admin')->id;
        app(SupportAccess::class)->admin($context->id(), $actor);
        $request->validate(['search' => 'nullable|string|max:120', 'page' => 'nullable|integer|min:1']);

        return response()->json($replies->visible($context->id(), $actor, $request->string('search'))->paginate(30))->header('Cache-Control', 'private, no-store');
    }
}
