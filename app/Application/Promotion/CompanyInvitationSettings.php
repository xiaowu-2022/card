<?php

namespace App\Application\Promotion;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Promotion\Models\CompanyInvitation;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/** Effective settings are projected from append-only evidence; original invitation stays immutable. */
final class CompanyInvitationSettings
{
    public function current(CompanyInvitation $invitation): array
    {
        $latest = DB::table('company_invitation_changes')->where('tenant_id', $invitation->tenant_id)
            ->where('company_invitation_id', $invitation->id)->orderByDesc('revision')->first();

        return ['id' => $invitation->id, 'code' => $latest?->new_code ?? $invitation->invitation_code,
            'enabled' => $latest ? (bool) $latest->enabled : true, 'revision' => (int) ($latest?->revision ?? 0)];
    }

    public function change(string $tenant, AdminUser $actor, array $input): object
    {
        $data = Validator::make($input, [
            'old_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'new_code' => ['required', 'string', 'regex:/^[0-9]{6}$/D'],
            'enabled' => ['required', 'boolean'], 'revision' => ['required', 'integer', 'min:0'],
            'reason' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted'], 'request_id' => ['required', 'uuid'],
        ])->validate();

        return DB::transaction(function () use ($tenant, $actor, $data) {
            $company = Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            $actor->refresh();
            abort_unless($actor->status === AdminUserStatus::Active && app(AuthorizationService::class)
                ->allows($actor, ScopeType::Platform, null, 'tenant.manage'), 403);
            $reason = trim($data['reason']);
            abort_if($reason === '', 422, 'Enter a reason for changing the invitation code.');
            $old = DB::table('company_invitation_changes')->where('tenant_id', $tenant)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless($old->actor_id === $actor->id && $old->old_code === $data['old_code'] && $old->new_code === $data['new_code']
                    && (bool) $old->enabled === (bool) $data['enabled'] && (int) $old->revision === (int) $data['revision'] + 1 && $old->reason === $reason, 409);

                return $old;
            }
            $invitation = CompanyInvitation::where('tenant_id', $tenant)->firstOrFail();
            $current = $this->current($invitation);
            if ($current['revision'] !== (int) $data['revision'] || $current['code'] !== $data['old_code']) {
                throw new DomainException('INVITATION_CHANGE_STALE', 'The invitation code has changed. Reload and try again.', 409);
            }
            abort_if($current['code'] === $data['new_code'] && $current['enabled'] === (bool) $data['enabled'], 422, 'No changes to save.');
            // Disabling must remain available even while a company is suspended.
            abort_if((bool) $data['enabled'] && $company->status->value !== 'ACTIVE', 409, 'Choose an active company.');
            $revision = $current['revision'] + 1;
            if ($data['new_code'] !== $current['code']) {
                $next = DB::table('promotion_invitation_counter')->where('id', 1)->lockForUpdate()->value('next_value');
                if ((int) $data['new_code'] < $next || DB::table('promotion_invitation_reservations')->where('code', $data['new_code'])->exists()) {
                    throw new DomainException('INVITATION_CODE_UNAVAILABLE', 'Choose an unused invitation code at or after the current allocation position.', 409);
                }
                DB::table('promotion_invitation_reservations')->insert(['code' => $data['new_code'], 'tenant_id' => $tenant,
                    'company_invitation_id' => $invitation->id, 'revision' => $revision]);
            }
            $id = (string) Str::uuid();
            DB::table('company_invitation_changes')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'company_invitation_id' => $invitation->id,
                'old_code' => $current['code'], 'new_code' => $data['new_code'], 'old_enabled' => $current['enabled'],
                'enabled' => (bool) $data['enabled'], 'revision' => $revision, 'actor_id' => $actor->id, 'actor_name' => $actor->name,
                'request_id' => $data['request_id'], 'reason' => $reason, 'created_at' => now()->toIso8601String(),
            ]);
            app(AuditLogger::class)->record($tenant, 'ADMIN', $actor->id, 'COMPANY_INVITATION_CHANGED', 'company_invitation_change', $id,
                ['code' => $current['code'], 'enabled' => $current['enabled']], ['code' => $data['new_code'], 'enabled' => (bool) $data['enabled']], $data['request_id']);

            return DB::table('company_invitation_changes')->where('id', $id)->first();
        }, 3);
    }
}
