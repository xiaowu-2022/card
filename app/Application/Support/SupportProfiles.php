<?php

namespace App\Application\Support;

use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

final class SupportProfiles
{
    public function agents()
    {
        return AdminUser::query()->whereHas('memberships', function ($q) {
            $q->where('status', 'ACTIVE')->where(function ($q) {
                $q->where(fn ($q) => $q->where('scope_type', 'TENANT')->whereNotNull('scope_id')->whereHas('role', fn ($r) => $r->where('scope_type', 'TENANT'))->whereHas('role.permissions', fn ($p) => $p->where('name', 'support.manage')))
                    ->orWhere(fn ($q) => $q->where('scope_type', 'PLATFORM')->whereNull('scope_id')->whereHas('role', fn ($r) => $r->where('scope_type', 'PLATFORM'))
                        ->whereHas('role.permissions', fn ($p) => $p->where('name', 'support.read'))
                        ->whereHas('role.permissions', fn ($p) => $p->where('name', 'support.send')));
            });
        });
    }

    public function update(string $actor, string $target, ?string $name, ?string $company = null, bool $self = false): void
    {
        $name = trim($name ?? '');
        Validator::make(['support_name' => $name], ['support_name' => ['nullable', 'string', 'max:30', 'not_regex:/[\x00-\x1F\x7F]/u']])->validate();
        DB::transaction(function () use ($actor, $target, $name, $company, $self) {
            $access = app(SupportAccess::class);
            if ($self) {
                abort_unless($actor === $target, 403);
                $company ? $access->admin($company, $actor) : $access->platform($actor, 'support.send');
            } else {
                $access->platform($actor, 'support.agents.manage');
            }
            $admin = $this->agents()->whereKey($target)->lockForUpdate()->firstOrFail();
            $old = $admin->support_name;
            $admin->update(['support_name' => $name === '' ? null : $name]);
            app(AuditLogger::class)->record($company, 'ADMIN', $actor, 'SUPPORT_NAME_UPDATED', 'admin_user', $target,
                ['support_name' => $old], ['support_name' => $admin->support_name]);
        });
    }
}
