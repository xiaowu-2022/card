<?php

namespace App\Http\Controllers\User;

use App\Application\Promotion\CompleteInvitedRegistrationAction;
use App\Application\Promotion\PromotionMembershipAction;
use App\Application\User\CreateRegistrationChallengeAction;
use App\Application\User\VerifyRegistrationChallengeAction;
use App\Domain\Notification\Contracts\EmailVerificationSender;
use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\TenantContext;
use App\Domain\User\Enums\RegistrationChallengeStatus;
use App\Domain\User\Enums\RegistrationChannel;
use App\Domain\User\Models\RegistrationChallenge;
use App\Http\Controllers\Controller;
use App\Http\Requests\CompleteRegistrationRequest;
use App\Http\Requests\CreateRegistrationChallengeRequest;
use App\Http\Requests\VerifyRegistrationChallengeRequest;
use App\Support\Errors\DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

final class RegistrationController extends Controller
{
    public function create(Request $request, EmailVerificationSender $emailSender, SmsVerificationSender $smsSender, TenantContext $context, PromotionMembershipAction $members): Response
    {
        $key = 'promotion.invitation.'.$context->id();
        $invitationInvalid = false;
        if (is_string($request->session()->get($key))) {
            try {
                $locked = $members->enrollment($context->id(), $request->session()->get($key));
                $request->session()->put($key, $locked['code']);
            } catch (DomainException $error) {
                if ($error->errorCode !== 'INVITATION_INVALID') {
                    throw $error;
                }
                // Only an invalid, unconsumed browser selection is released.
                // Persisted challenge inviters and member relationships are never rewritten.
                $request->session()->forget($key);
                $invitationInvalid = true;
            }
        }
        if (! $request->session()->has($key) && is_string($request->query('invite')) && $request->query('invite') !== '') {
            try {
                $inviter = $members->enrollment($context->id(), $request->query('invite'));
                $request->session()->put($key, $inviter['code']);
                $invitationInvalid = false;
            } catch (DomainException $error) {
                if ($error->errorCode !== 'INVITATION_INVALID') {
                    throw $error;
                }
                $invitationInvalid = true;
            }
        }

        return Inertia::render('user/Register', [
            'registration' => [
                'emailAvailable' => $emailSender->isAvailable($context->tenant()),
                'phoneAvailable' => $smsSender->isAvailable($context->tenant()),
                'invitationCode' => $request->session()->get($key, ''),
                'invitationLocked' => $request->session()->has($key),
                'invitationInvalid' => $invitationInvalid,
            ],
        ]);
    }

    public function storeChallenge(CreateRegistrationChallengeRequest $request, TenantContext $context, CreateRegistrationChallengeAction $action, PromotionMembershipAction $members): RedirectResponse
    {
        $inviter = $members->enrollment($context->id(), $request->validated('invitation_code'));
        $code = $inviter['code'];
        $locked = $request->session()->get('promotion.invitation.'.$context->id());
        if (is_string($locked) && ! hash_equals($members->enrollment($context->id(), $locked)['code'], $code)) {
            throw new DomainException('INVITATION_IMMUTABLE', 'The invitation relationship cannot be changed.');
        }
        $request->ensureIsNotRateLimited($context->id());
        $request->hitRateLimiters($context->id());
        $ownedChallengeIds = $this->ownedChallengeIds($request);
        $created = $action->execute(
            $context->tenant(),
            RegistrationChannel::from($request->string('channel')->toString()),
            $request->string('destination')->toString(),
            $request->string('region')->toString() ?: null,
            $request->attributes->get('request_id'),
            $ownedChallengeIds,
            $inviter['memberId'],
            $inviter['companyId'],
        );
        $request->session()->put('registration.challenge_ids', array_slice(array_values(array_unique([
            ...$ownedChallengeIds,
            $created->challenge->id,
        ])), -10));

        return redirect("/register/challenges/{$created->challenge->id}")
            ->with('success', $created->deliveryUncertain
                ? 'Delivery is not confirmed. If a code arrives, enter it here. Wait for this request to expire before requesting a new code.'
                : 'If this contact can be used for registration, verification instructions have been sent.');
    }

    public function showChallenge(Request $request, string $challenge, TenantContext $context): Response
    {
        $this->assertSessionOwnsChallenge($request, $challenge);
        $model = RegistrationChallenge::query()->where('tenant_id', $context->id())->whereKey($challenge)->first();
        if (! $model || ! in_array($model->status, [RegistrationChallengeStatus::Pending, RegistrationChallengeStatus::Verified], true) || $model->expires_at->isPast()) {
            throw new DomainException('REGISTRATION_CHALLENGE_INVALID', 'This verification request is invalid or expired.');
        }

        return Inertia::render('user/VerifyRegistration', [
            'challenge' => ['id' => $model->id, 'channel' => $model->channel->value, 'status' => $model->status->value],
        ]);
    }

    public function verify(VerifyRegistrationChallengeRequest $request, string $challenge, TenantContext $context, VerifyRegistrationChallengeAction $action): RedirectResponse
    {
        $this->assertSessionOwnsChallenge($request, $challenge);
        $request->ensureIsNotRateLimited($context->id(), $challenge);
        $request->hitRateLimiter($context->id(), $challenge);
        $action->execute($context->id(), $challenge, $request->string('code')->toString(), $request->attributes->get('request_id'));

        return redirect("/register/challenges/{$challenge}")->with('success', 'Contact verified. Create your password to finish.');
    }

    public function complete(CompleteRegistrationRequest $request, string $challenge, TenantContext $context, CompleteInvitedRegistrationAction $action): RedirectResponse
    {
        $this->assertSessionOwnsChallenge($request, $challenge);
        $user = $action->execute(
            $context->tenant(),
            $challenge,
            $request->string('password')->toString(),
            $request->string('display_name')->toString() ?: null,
            $request->string('locale')->toString() ?: null,
            $request->attributes->get('request_id'),
        );
        Auth::guard('tenant_user')->login($user);
        $request->session()->put('tenant_user_session_version', $user->fresh()->session_version);
        $request->session()->regenerate();
        $request->session()->forget('registration.challenge_ids');
        $request->session()->forget('promotion.invitation.'.$context->id());

        return redirect('/dashboard')->with('success', 'Your account is ready.');
    }

    /** @return list<string> */
    private function ownedChallengeIds(Request $request): array
    {
        return array_values(array_filter(
            (array) $request->session()->get('registration.challenge_ids', []),
            fn (mixed $id): bool => is_string($id),
        ));
    }

    private function assertSessionOwnsChallenge(Request $request, string $challengeId): void
    {
        if (! in_array($challengeId, $this->ownedChallengeIds($request), true)) {
            throw new DomainException('REGISTRATION_CHALLENGE_INVALID', 'This verification request is invalid or expired.');
        }
    }
}
