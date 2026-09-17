<?php

namespace App\Http\Controllers\User;

use App\Application\Promotion\AccountActivationStatus;
use App\Application\User\AccountInformationQuery;
use App\Application\User\ChangeUserContactAction;
use App\Application\User\ChangeUserPasswordAction;
use App\Application\User\RevokeOtherUserSessionsAction;
use App\Application\User\UpdateUserNameAction;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\Tenant\Enums\TenantStatus;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\ChangeUserContactRequest;
use App\Http\Requests\ChangeUserPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

final class AccountController extends Controller
{
    public function show(TenantContext $context, KycStatusService $kyc, AccountActivationStatus $activationStatus): Response
    {
        $userId = Auth::guard('tenant_user')->id();
        $activation = $activationStatus->get($context->id(), $userId);

        return Inertia::render('user/Account', [
            'kycStatus' => $kyc->forUser($context->id(), $userId)->value,
            'promotionRank' => (int) $activation['rank'],
            'accountQualified' => $activation['qualified'],
        ]);
    }

    public function settings(): Response
    {
        return Inertia::render('user/AccountSettings');
    }

    public function restricted(TenantContext $context): Response|RedirectResponse
    {
        /** @var User|null $user */
        $user = Auth::guard('tenant_user')->user();
        if ($context->tenant()->status === TenantStatus::Active && $user?->status === UserStatus::Active) {
            return redirect('/dashboard');
        }

        return Inertia::render('user/Restricted', ['tenantRestricted' => $context->tenant()->status === TenantStatus::Suspended]);
    }

    public function security(Request $request, TenantContext $context, AccountInformationQuery $query, KycStatusService $kyc): \Symfony\Component\HttpFoundation\Response
    {
        return Inertia::render('user/Security', ['kycStatus' => $kyc->forUser($context->id(), Auth::guard('tenant_user')->id())->value, 'information' => $query->execute(
            $context->id(), Auth::guard('tenant_user')->id(),
            $request->session()->get('contact_change_request'), $this->binding($request),
        )])->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function updateName(Request $request, TenantContext $context, UpdateUserNameAction $action): RedirectResponse
    {
        $data = $request->validate(['display_name' => ['required', 'string', 'max:80']]);
        $action->execute($context->id(), Auth::guard('tenant_user')->id(), $data['display_name']);

        return back()->with('success', 'Name updated.');
    }

    public function startContact(ChangeUserContactRequest $request, TenantContext $context, ChangeUserContactAction $action): RedirectResponse
    {
        $request->session()->put('contact_change_request', $request->string('request_id')->toString());
        $action->start($context->id(), Auth::guard('tenant_user')->id(), RegistrationChannel::from($request->string('channel')->toString()),
            $request->string('new_contact')->toString(), $request->string('current_password')->toString(),
            $this->binding($request), $request->string('request_id')->toString());

        return back();
    }

    public function completeContact(Request $request, TenantContext $context, ChangeUserContactAction $action, string $change): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[0-9]{6}$/'], 'confirmed' => ['accepted']]);
        $action->complete($context->id(), Auth::guard('tenant_user')->id(), $change, $this->binding($request), $data['code']);
        $request->session()->forget('contact_change_request');
        $request->session()->regenerate();

        return back()->with('success', 'Contact updated. Use the new contact next time you log in.');
    }

    private function binding(Request $request): string
    {
        if (! $request->session()->has('contact_change_binding')) {
            $request->session()->put('contact_change_binding', Str::random(64));
        }

        return $request->session()->get('contact_change_binding');
    }

    public function changePassword(ChangeUserPasswordRequest $request, TenantContext $context, ChangeUserPasswordAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $version = $action->execute($context->id(), $user->id, $request->string('current_password')->toString(), $request->string('password')->toString(), $request->attributes->get('request_id'));
        $user->session_version = $version;
        $request->session()->put('tenant_user_session_version', $version);
        $request->session()->regenerate();

        return back()->with('success', 'Password changed successfully.');
    }

    public function revokeSessions(Request $request, TenantContext $context, RevokeOtherUserSessionsAction $action): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'string', 'max:1024'], 'confirmed' => ['accepted']]);
        /** @var User $user */
        $user = Auth::guard('tenant_user')->user();
        $version = $action->execute($context->id(), $user->id, $data['current_password']);
        $user->session_version = $version;
        $request->session()->put('tenant_user_session_version', $version);
        $request->session()->regenerate();

        return back()->with('success', 'Other devices must sign in again. This device stays signed in.');
    }
}
