<?php

namespace App\Http\Controllers\User;

use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\RegisterUserAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteRegistrationRequest;
use App\Http\Requests\CreateRegistrationChallengeRequest;
use App\Http\Requests\VerifyRegistrationChallengeRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class RegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('user/Register');
    }

    public function storeChallenge(CreateRegistrationChallengeRequest $request, TenantContext $context, CreateRegistrationChallengeAction $action): RedirectResponse
    {
        $request->ensureIsNotRateLimited($context->id());
        $created = $action->execute(
            $context->tenant(),
            RegistrationChannel::from($request->string('channel')->toString()),
            $request->string('destination')->toString(),
            $request->string('region')->toString() ?: null,
            $request->attributes->get('request_id'),
        );
        $request->hitRateLimiters($context->id());

        return redirect("/register/challenges/{$created->challenge->id}")
            ->with('success', 'If this contact can be used for registration, verification instructions have been sent.');
    }

    public function showChallenge(string $challenge, TenantContext $context): Response
    {
        $model = RegistrationChallenge::query()->where('tenant_id', $context->id())->whereKey($challenge)->firstOrFail();

        return Inertia::render('user/VerifyRegistration', [
            'challenge' => ['id' => $model->id, 'channel' => $model->channel->value, 'status' => $model->status->value],
        ]);
    }

    public function verify(VerifyRegistrationChallengeRequest $request, string $challenge, TenantContext $context, VerifyRegistrationChallengeAction $action): RedirectResponse
    {
        $request->ensureIsNotRateLimited($context->id(), $challenge);
        $request->hitRateLimiter($context->id(), $challenge);
        $action->execute($context->id(), $challenge, $request->string('code')->toString(), $request->attributes->get('request_id'));

        return redirect("/register/challenges/{$challenge}")->with('success', 'Contact verified. Create your password to finish.');
    }

    public function complete(CompleteRegistrationRequest $request, string $challenge, TenantContext $context, RegisterUserAction $action): RedirectResponse
    {
        $user = $action->execute(
            $context->tenant(),
            $challenge,
            $request->string('password')->toString(),
            $request->string('display_name')->toString() ?: null,
            $request->string('locale')->toString() ?: null,
            $request->attributes->get('request_id'),
        );
        Auth::guard('tenant_user')->login($user);
        $request->session()->regenerate();

        return redirect('/dashboard')->with('success', 'Your account is ready.');
    }
}
