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
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class ChangeInvitationCode
{
    public function execute(string $tenant, string $user, AdminUser $actor, array $data): object
    {
        $data = Validator::make($data, [
            'new_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'old_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'revision' => ['required', 'integer', 'min:0'], 'reason' => ['required', 'string', 'max:500'],
            'request_id' => ['required', 'uuid'], 'confirmed' => ['required', 'accepted'],
        ])->validate();

        return DB::transaction(function () use ($tenant, $user, $actor, $data) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $subject = User::where('tenant_id', $tenant)->whereKey($user)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            $auth = app(AuthorizationService::class);
            abort_unless($actor->status === AdminUserStatus::Active
                && $auth->allows($actor, ScopeType::Platform, null, 'users.read')
                && $auth->allows($actor, ScopeType::Platform, null, 'users.invitation.manage'), 403);
            $reason = trim($data['reason']);
            if ($reason === '') {
                throw new DomainException('INVITATION_CHANGE_INVALID', 'Enter a reason for changing the invitation code.');
            }
            $member = PromotionMember::where('tenant_id', $tenant)->where('user_id', $user)->lockForUpdate()->firstOrFail();
            $old = DB::table('promotion_invitation_changes')->where('tenant_id', $tenant)->where('user_id', $user)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->actor_id === $actor->id && $old->new_code === $data['new_code'] && $old->old_code === $data['old_code']
                    && (int) $old->revision === (int) $data['revision'] + 1 && $old->reason === $reason, 409);

                return $old;
            }
            if ($company->status->value !== 'ACTIVE' || $subject->status->value !== 'ACTIVE') {
                throw new DomainException('INVITATION_CHANGE_INACTIVE', 'Choose an active user in an active company.');
            }
            if ($member->invitation_code !== $data['old_code'] || (int) $member->invitation_revision !== (int) $data['revision']) {
                throw new DomainException('INVITATION_CHANGE_STALE', 'The invitation code has changed. Reload and try again.', 409);
            }
            $next = DB::table('promotion_invitation_counter')->where('id', 1)->lockForUpdate()->value('next_value');
            if ((int) $data['new_code'] < $next || DB::table('promotion_invitation_reservations')->where('code', $data['new_code'])->exists()) {
                throw new DomainException('INVITATION_CODE_UNAVAILABLE', 'Choose an unused invitation code at or after the current allocation position.', 409);
            }
            $id = (string) Str::uuid();
            DB::table('promotion_invitation_reservations')->insert(['code' => $data['new_code'], 'tenant_id' => $tenant, 'member_id' => $member->id, 'revision' => $member->invitation_revision + 1]);
            DB::table('promotion_invitation_changes')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'user_id' => $user, 'member_id' => $member->id,
                'old_code' => $member->invitation_code, 'new_code' => $data['new_code'], 'revision' => $member->invitation_revision + 1,
                'actor_id' => $actor->id, 'actor_name' => $actor->name, 'request_id' => $data['request_id'], 'reason' => $reason,
                'created_at' => now()->format('Y-m-d H:i:s.uP'),
            ]);
            $member->update(['invitation_code' => $data['new_code'], 'invitation_revision' => $member->invitation_revision + 1]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'USER_INVITATION_CODE_CHANGED', 'promotion_invitation_change', $id,
                ['code' => $data['old_code']], ['code' => $data['new_code']], $data['request_id']);

            return DB::table('promotion_invitation_changes')->where('id', $id)->first();
        }, 3);
    }
}
