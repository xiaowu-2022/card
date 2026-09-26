<?php

namespace App\Http\Controllers\Api;

use App\Application\Assets\AssetOverviewQuery;
use App\Application\Card\UserCardCenterQuery;
use App\Application\Inbox\InboxQuery;
use App\Application\Promotion\AccountActivationStatus;
use App\Application\Support\SendSupportMessageAction;
use App\Application\Support\SupportChatQuery;
use App\Application\Support\SupportImageStorage;
use App\Application\Support\SupportUnread;
use App\Application\User\AuthenticateUserAction;
use App\Application\User\IssueConsumerDeviceToken;
use App\Application\User\UpdateUserLocaleAction;
use App\Application\Wallet\UserWalletQuery;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\SendSupportMessageRequest;
use App\Http\Requests\UserLoginRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

final class ConsumerController extends Controller
{
    public function assets(Request $request, TenantContext $context, AssetOverviewQuery $assets, UserWalletQuery $wallets)
    {
        $user = $request->attributes->get('consumer_user')->id;

        return response()->json($assets->get($context->id(), $user, $wallets->get($context->id(), $user)));
    }

    public function cards(Request $request, TenantContext $context, UserCardCenterQuery $query)
    {
        $data = $query->get($context->id(), $request->attributes->get('consumer_user')->id);

        return response()->json(['cards' => $data['cards']]);
    }

    public function bootstrap(Request $request, TenantContext $context, InboxQuery $inbox, SupportUnread $support)
    {
        $tenant = $context->tenant();
        $user = $request->attributes->get('consumer_user');
        $authenticated = $user instanceof User;

        return response()->json([
            'apiVersion' => 1,
            'tenant' => ['id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->branding?->brand_name ?? $tenant->name,
                'logoUrl' => $tenant->branding?->logo_object_key ? Storage::disk('public')->url($tenant->branding->logo_object_key) : null,
                'primaryColor' => $tenant->branding?->primary_color ?? '#39AD8D'],
            'locale' => $request->attributes->get('client_locale', 'en'),
            'locales' => $request->attributes->get('client_locales', ['en']),
            'timezone' => $request->attributes->get('client_timezone', 'UTC'),
            'user' => $authenticated ? ['id' => $user->id, 'accountId' => $user->account_id, 'displayName' => $user->profile?->display_name, 'email' => $user->email] : null,
            'restricted' => $tenant->status !== TenantStatus::Active || ($authenticated && $user->status !== UserStatus::Active),
            'unread' => ['messages' => $authenticated ? $inbox->unread($tenant->id, $user->id) : 0,
                'support' => $authenticated ? $support->count($tenant->id, $user->id) : 0],
            'csrfToken' => $request->hasSession() ? $request->session()->token() : null,
        ]);
    }

    public function login(UserLoginRequest $request, TenantContext $context, AuthenticateUserAction $authenticate, IssueConsumerDeviceToken $devices)
    {
        $request->validate(['device_name' => 'sometimes|string|max:80']);
        $request->ensureIsNotRateLimited($context->id());
        $user = $authenticate->execute($context->id(), $request->string('identifier')->toString(), $request->string('password')->toString(), null,
            $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        if (! $user) {
            $request->hitRateLimiter($context->id());
            throw ValidationException::withMessages(['identifier' => 'Invalid credentials.']);
        }
        $request->clearRateLimiter($context->id());
        if ($request->attributes->get('consumer_mode') === 'mobile') {
            return response()->json($devices->execute($context->id(), $user->id, $request->string('password')->toString(), $request->input('device_name', 'Mobile')), 201);
        }
        Auth::guard('tenant_user')->login($user);
        $request->session()->put('tenant_user_session_version', $user->session_version);
        $request->session()->regenerate();

        return response()->json(['csrfToken' => $request->session()->token()]);
    }

    public function logout(Request $request, TenantContext $context, AuditLogger $audit)
    {
        $user = $request->attributes->get('consumer_user');
        $audit->record($context->id(), 'USER', $user->id, 'USER_LOGOUT', 'user_authentication', null, null, null,
            $request->attributes->get('request_id'), $request->ip(), $request->userAgent());
        if ($request->attributes->get('consumer_mode') === 'mobile') {
            $request->attributes->get('consumer_token')->delete();
        } else {
            Auth::guard('tenant_user')->logout();
            $request->session()->forget(['tenant_user_session_version', 'contact_change_binding', 'contact_change_request']);
            $request->session()->regenerate(true);
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    public function account(Request $request, TenantContext $context, KycStatusService $kyc, AccountActivationStatus $activation)
    {
        $user = $request->attributes->get('consumer_user');
        $status = $activation->get($context->id(), $user->id);

        return response()->json(['kycStatus' => $kyc->forUser($context->id(), $user->id)->value,
            'promotionRank' => (int) $status['rank'], 'accountQualified' => $status['qualified']]);
    }

    public function locale(Request $request, TenantContext $context, UpdateUserLocaleAction $update)
    {
        $data = $request->validate(['locale' => 'required|string|max:16', 'tenant_id' => 'prohibited', 'user_id' => 'prohibited']);
        $update->execute($context->tenant(), $request->attributes->get('consumer_user'), $data['locale']);

        return response()->json(['locale' => $data['locale']]);
    }

    public function unread(Request $request, TenantContext $context, InboxQuery $inbox, SupportUnread $support)
    {
        $user = $request->attributes->get('consumer_user')->id;

        return response()->json(['messages' => $inbox->unread($context->id(), $user), 'support' => $support->count($context->id(), $user)]);
    }

    public function messages(Request $request, TenantContext $context, InboxQuery $query)
    {
        $data = $request->validate(['filter' => 'sometimes|in:all,unread,business,platform', 'page' => 'sometimes|integer|min:1|max:100000']);
        $page = $query->listing($context->id(), $request->attributes->get('consumer_user')->id, $data['filter'] ?? 'all');

        return response()->json(['items' => $page->items(), 'page' => $page->currentPage(), 'hasMore' => $page->hasMorePages()]);
    }

    public function message(Request $request, TenantContext $context, InboxQuery $query, string $message)
    {
        return response()->json($query->detail($context->id(), $request->attributes->get('consumer_user')->id, $message));
    }

    public function read(Request $request, TenantContext $context, InboxQuery $query, ?string $message = null)
    {
        $query->read($context->id(), $request->attributes->get('consumer_user')->id, $message);

        return response()->noContent();
    }

    public function support(Request $request, TenantContext $context, SupportChatQuery $query)
    {
        $request->validate(['before' => 'sometimes|integer|min:0|max:2147483647']);

        return response()->json($query->user($context->id(), $request->attributes->get('consumer_user')->id, $request->integer('before')));
    }

    public function supportRead(Request $request, TenantContext $context, SupportUnread $unread)
    {
        $data = $request->validate(['through' => 'required|integer|min:1|max:2147483647']);
        $unread->read($context->id(), $request->attributes->get('consumer_user')->id, $data['through']);

        return response()->noContent();
    }

    public function supportSend(SendSupportMessageRequest $request, TenantContext $context, SendSupportMessageAction $action)
    {
        $action->user($context->id(), $request->attributes->get('consumer_user')->id, $request->validated('request_id'),
            $request->validated('support_message') ?? '', $request->file('support_image'));

        return response()->noContent();
    }

    public function supportImage(Request $request, TenantContext $context, SupportChatQuery $query, SupportImageStorage $storage, string $message)
    {
        $image = $query->image($context->id(), $request->attributes->get('consumer_user')->id, $message, false);

        return response($storage->read($image['path']), 200, ['Content-Type' => $image['mime'], 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }
}
