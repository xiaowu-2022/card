<?php
namespace App\Application\Support;

use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportCustomerRemark
{
    public function save(string $tenant, string $actor, array $input, ?string $conversation = null, ?string $userId = null): void
    {
        $data = Validator::make($input, ['remark' => ['nullable', 'string', 'max:60', 'not_regex:/[\x00-\x1F\x7F]/u'], 'revision' => 'required|integer|min:0'])->validate();
        DB::transaction(function () use ($tenant, $actor, $data, $conversation, $userId) {
            Tenant::whereKey($tenant)->lockForUpdate()->firstOrFail();
            if ($conversation) {
                $userId = app(SupportUserAgents::class)->conversation($tenant, $actor, $conversation)->user_id;
            } else {
                app(SupportAccess::class)->platform($actor, 'users.read');
                app(SupportAccess::class)->platform($actor, 'support.send');
            }
            $user = User::where('tenant_id', $tenant)->whereKey($userId)->lockForUpdate()->firstOrFail();
            abort_if((int) $user->support_remark_revision !== (int) $data['revision'], 409, 'Customer remark changed. Refresh and try again.');
            $old = $user->support_remark;
            $remark = trim($data['remark'] ?? '');
            $remark = $remark === '' ? null : $remark;
            $user->forceFill(['support_remark' => $remark, 'support_remark_revision' => $user->support_remark_revision + 1])->save();
            app(AuditLogger::class)->record($tenant, $conversation ? 'USER' : 'ADMIN', $actor, 'CUSTOMER_REMARK_UPDATED', 'user', $user->id, ['remark' => $old], ['remark' => $remark]);
        });
    }
}
