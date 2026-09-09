<?php

namespace App\Application\Admin;

use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final readonly class CancelAdminInvitationAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(Tenant $tenant, string $invitationId, AdminUser $actor, ?string $requestId = null): void
    {
        DB::transaction(function () use ($tenant, $invitationId, $actor, $requestId): void {
            $invitation = AdminInvitation::query()->where('tenant_id', $tenant->id)->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($invitation->status !== InvitationStatus::Pending) {
                throw new DomainException('INVITATION_NOT_PENDING', 'Only a pending invitation can be cancelled.');
            }

            $invitation->update(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()]);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'ADMIN_INVITATION_CANCELLED', 'admin_invitation', $invitation->id, ['status' => InvitationStatus::Pending->value], ['status' => InvitationStatus::Cancelled->value], $requestId);
        });
    }
}
