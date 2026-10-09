<?php

namespace App\Application\User;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class UpdateUserOperationRestrictions
{
    public function execute(string $tenantId, string $userId, AdminUser $actor, array $input): array
    {
        $data = Validator::make($input, array_merge(array_fill_keys(UserOperationRestrictions::FIELDS, ['required', 'boolean']), [
            'revision' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted'], 'request_id' => ['required', 'uuid'],
        ]))->validate();

        return DB::transaction(function () use ($tenantId, $userId, $actor, $data): array {
            $actor = $actor->fresh();
            $authorization = app(AuthorizationService::class);
            abort_unless($actor && $actor->status->value === 'ACTIVE'
                && $authorization->allows($actor, ScopeType::Platform, null, 'users.read')
                && $authorization->allows($actor, ScopeType::Platform, null, 'users.restrictions.manage'), 403);
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            $values = [];
            foreach (UserOperationRestrictions::FIELDS as $field) {
                $values[$field] = (bool) $data[$field];
            }
            $after = $values + ['revision' => (int) $data['revision'] + 1];
            $previous = AuditLog::where('tenant_id', $tenantId)->where('resource_id', $userId)
                ->where('action', 'USER_OPERATION_RESTRICTIONS_UPDATED')->where('request_id', $data['request_id'])->first();
            if ($previous) {
                abort_unless($previous->actor_id === $actor->id && $previous->after_data == $after, 409);

                return $after;
            }
            abort_unless((int) $user->operation_restrictions_revision === (int) $data['revision'], 409);
            $before = UserOperationRestrictions::values($user) + ['revision' => (int) $user->operation_restrictions_revision];
            $user->update($values + ['operation_restrictions_revision' => $after['revision']]);
            app(AuditLogger::class)->record($tenantId, 'ADMIN', $actor->id, 'USER_OPERATION_RESTRICTIONS_UPDATED', 'user', $userId, $before, $after, $data['request_id']);

            return $after;
        }, 3);
    }
}
