<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Promotion\Models\PromotionMember;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ChangeReferrer
{
    public function descendants(string $tenant, string $member): array
    {
        return array_column(DB::select('WITH RECURSIVE team AS (
            SELECT id FROM promotion_members WHERE tenant_id=? AND inviter_id=?
            UNION SELECT m.id FROM promotion_members m JOIN team t ON m.inviter_id=t.id WHERE m.tenant_id=?
        ) SELECT id FROM team', [$tenant, $member, $tenant]), 'id');
    }

    public function execute(string $tenant, string $user, AdminUser $actor, array $data): object
    {
        return DB::transaction(function () use ($tenant, $user, $actor, $data) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $subject = User::where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $auth = app(AuthorizationService::class);
            abort_unless($actor->status === AdminUserStatus::Active
                && $auth->allows($actor, ScopeType::Platform, null, 'users.read')
                && $auth->allows($actor, ScopeType::Platform, null, 'users.referrer.manage'), 403);
            $reason = trim($data['reason']);
            if ($reason === '' || mb_strlen($reason) > 500 || ! Str::isUuid($data['request_id'])) {
                throw new DomainException('REFERRER_INVALID', 'Enter a reason for changing the referrer.');
            }
            $old = DB::table('referrer_changes')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->actor_id === $actor->id && $old->new_inviter_id === $data['new_inviter_id']
                    && $old->old_inviter_id === ($data['old_inviter_id'] ?? null) && $old->revision === (int) $data['revision'] + 1 && $old->reason === $reason, 409);

                return $old;
            }
            $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->lockForUpdate()->firstOrFail();
            abort_unless($member->inviter_id === ($data['old_inviter_id'] ?? null) && (int) $member->referrer_revision === (int) $data['revision'], 409);
            $target = PromotionMember::where('tenant_id', $tenant)->findOrFail($data['new_inviter_id']);
            $targetUser = User::where('tenant_id', $tenant)->whereKey($target->user_id)->firstOrFail();
            $descendants = $this->descendants($tenant, $member->id);
            if ($company->status->value !== 'ACTIVE' || $subject->status->value !== 'ACTIVE' || $targetUser->status->value !== 'ACTIVE'
                || $target->id === $member->id || $target->id === $member->inviter_id || in_array($target->id, $descendants, true)) {
                throw new DomainException('REFERRER_INVALID', 'Choose an active company member outside this user’s team.');
            }
            $id = (string) Str::uuid();
            DB::table('referrer_changes')->insert(['id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'member_id' => $member->id,
                'old_inviter_id' => $member->inviter_id, 'new_inviter_id' => $target->id, 'revision' => $member->referrer_revision + 1,
                'actor_id' => $actor->id, 'actor_name' => $actor->name, 'request_id' => $data['request_id'], 'reason' => $reason,
                'descendants' => count($descendants), 'created_at' => now()->format('Y-m-d H:i:s.uP')]);
            $member->update(['inviter_id' => $target->id, 'referrer_revision' => $member->referrer_revision + 1]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'USER_REFERRER_CHANGED', 'referrer_change', $id,
                ['inviter_id' => $data['old_inviter_id'] ?? null], ['inviter_id' => $target->id, 'descendants' => count($descendants)], $data['request_id']);

            return DB::table('referrer_changes')->where('id', $id)->first();
        }, 3);
    }
}
