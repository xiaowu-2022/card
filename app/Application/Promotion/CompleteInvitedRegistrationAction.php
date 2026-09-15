<?php

namespace App\Application\Promotion;

use App\Application\User\RegisterUserAction;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\RegistrationChallenge;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CompleteInvitedRegistrationAction
{
    public function __construct(private RegisterUserAction $register, private PromotionMembershipAction $members) {}

    public function execute(Tenant $tenant, string $challengeId, string $password, ?string $displayName = null, ?string $locale = null, ?string $requestId = null): User
    {
        return DB::transaction(function () use ($tenant, $challengeId, $password, $displayName, $locale, $requestId): User {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $challenge = RegistrationChallenge::query()->where('tenant_id', $tenant->id)->whereKey($challengeId)->lockForUpdate()->firstOrFail();
            if (! $challenge->promotion_inviter_id && ! $challenge->promotion_company_invitation_id) {
                throw new DomainException('INVITATION_INVALID', 'Enter a valid invitation code.');
            }
            $user = $this->register->execute($tenant, $challengeId, $password, $displayName, $locale, $requestId);
            $this->members->ensure($tenant->id, $user->id, $challenge->promotion_inviter_id);

            return $user;
        });
    }
}
