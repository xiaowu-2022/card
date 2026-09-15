<?php

namespace App\Http\Controllers\User;

use App\Application\User\ResetForgottenUserPasswordAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\RegistrationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteUserPasswordResetRequest;
use App\Http\Requests\StartUserPasswordResetRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

final class ForgotPasswordController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('user/ForgotPassword')->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function start(StartUserPasswordResetRequest $request, TenantContext $context, ResetForgottenUserPasswordAction $action): RedirectResponse
    {
        $reset = $action->start($context->id(), RegistrationChannel::from($request->string('channel')->toString()),
            $request->string('reset_contact')->toString(), $this->binding($request), $request->ip() ?? 'unknown', $request->string('request_id')->toString());

        return redirect('/forgot-password/'.$reset->id);
    }

    public function show(Request $request, TenantContext $context, ResetForgottenUserPasswordAction $action, string $reset): Response
    {
        return Inertia::render('user/ResetPassword', ['reset' => $action->view($context->id(), $reset, $this->binding($request))])
            ->toResponse($request)->header('Cache-Control', 'private, no-store');
    }

    public function complete(CompleteUserPasswordResetRequest $request, TenantContext $context, ResetForgottenUserPasswordAction $action, string $reset): RedirectResponse
    {
        $action->complete($context->id(), $reset, $this->binding($request), $request->string('code')->toString(), $request->string('password')->toString());
        $request->session()->forget(['tenant_user_session_version', 'contact_change_request', 'contact_change_binding']);
        $request->session()->regenerate(true);

        return redirect('/login')->with('success', 'Password reset. Sign in with your new password.');
    }

    private function binding(Request $request): string
    {
        if (! $request->session()->has('password_reset_binding')) {
            $request->session()->put('password_reset_binding', Str::random(64));
        }

        return $request->session()->get('password_reset_binding');
    }
}
