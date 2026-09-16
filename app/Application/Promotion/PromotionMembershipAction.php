<?php

namespace App\Application\Promotion;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Promotion\Models\CompanyInvitation;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Enums\UserStatus;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final readonly class PromotionMembershipAction
{
    public function __construct(private AuditLogger $audit) {}

    public function ensure(string $tenantId, string $userId, ?string $inviterId = null): PromotionMember
    {
        return DB::transaction(function () use ($tenantId, $userId, $inviterId): PromotionMember {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();
            User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
            $existing = PromotionMember::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->first();
            if ($existing) {
                if ($inviterId !== null && $existing->inviter_id !== $inviterId) {
                    throw new DomainException('INVITATION_IMMUTABLE', 'The invitation relationship cannot be changed.', 409);
                }

                return $existing;
            }
            if ($inviterId !== null) {
                $inviter = PromotionMember::query()->where('tenant_id', $tenantId)->whereKey($inviterId)->firstOrFail();
                if ($inviter->user_id === $userId) {
                    throw new DomainException('INVITATION_INVALID', 'Enter a valid invitation code.');
                }
            }

            return $this->createWithCode(fn () => PromotionMember::query()->create([
                'tenant_id' => $tenantId, 'user_id' => $userId, 'inviter_id' => $inviterId,
            ])->refresh());
        });
    }

    public function resolve(string $tenantId, string $code): PromotionMember
    {
        $member = PromotionMember::query()->where('tenant_id', $tenantId)->where('invitation_code', $this->canonicalCode($tenantId, $code))->first();
        if (! $member || ! User::query()->where('tenant_id', $tenantId)->whereKey($member->user_id)->where('status', UserStatus::Active)->exists()) {
            throw new DomainException('INVITATION_INVALID', 'Enter a valid invitation code.');
        }

        return $member;
    }

    public function companyInvitation(string $tenantId): CompanyInvitation
    {
        return DB::transaction(function () use ($tenantId) {
            Tenant::query()->whereKey($tenantId)->lockForUpdate()->firstOrFail();

            return CompanyInvitation::query()->where('tenant_id', $tenantId)->first()
                ?? $this->createWithCode(fn () => CompanyInvitation::query()->create(['tenant_id' => $tenantId])->refresh());
        });
    }

    public function enrollment(string $tenantId, string $code): array
    {
        $code = $this->canonicalCode($tenantId, $code);
        $company = CompanyInvitation::query()->where('tenant_id', $tenantId)->where('invitation_code', $code)->first();
        if ($company) {
            return ['code' => $company->invitation_code, 'memberId' => null, 'companyId' => $company->id];
        }
        $member = $this->resolve($tenantId, $code);

        return ['code' => $member->invitation_code, 'memberId' => $member->id, 'companyId' => null];
    }

    public function assignDirectLevel(string $tenantId, string $actorUserId, string $memberId, ?string $levelId): void
    {
        throw new DomainException('PROMOTION_PAYMENT_REQUIRED', 'Paid promotion levels require a successful annual fee payment.', 403);
    }

    private function canonicalCode(string $tenantId, string $code): string
    {
        $code = strtoupper(trim($code));
        if (preg_match('/^[A-F0-9]{24}$/D', $code)) {
            $alias = DB::table('promotion_invitation_aliases')->where('tenant_id', $tenantId)->where('old_code', $code)->first();
            if ($alias) {
                $code = $alias->member_id
                    ? PromotionMember::query()->where('tenant_id', $tenantId)->whereKey($alias->member_id)->value('invitation_code')
                    : CompanyInvitation::query()->where('tenant_id', $tenantId)->whereKey($alias->company_invitation_id)->value('invitation_code');
            }
        }

        return $code;
    }

    private function createWithCode(\Closure $create): PromotionMember|CompanyInvitation
    {
        try {
            return $create();
        } catch (QueryException $error) {
            if (str_contains($error->getMessage(), 'PROMOTION_INVITATION_EXHAUSTED')) {
                throw new DomainException('INVITATION_CODE_EXHAUSTED', 'Registration is temporarily unavailable.', 409);
            }
            throw $error;
        }
    }
}
