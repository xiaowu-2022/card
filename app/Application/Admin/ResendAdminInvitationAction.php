<?php

namespace App\Application\Admin;

use App\Application\Admin\DTOs\IssuedAdminInvitation;
use App\Domain\Admin\Enums\InvitationStatus;
use App\Domain\Admin\Models\AdminInvitation;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\AdminInvitationMail;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class ResendAdminInvitationAction
{
    public function __construct(
        private IssueAdminInvitationAction $issuer,
        private AuditLogger $audit,
    ) {}

    public function execute(Tenant $tenant, string $invitationId, AdminUser $actor, ?string $requestId = null): IssuedAdminInvitation
    {
        $issued = DB::transaction(function () use ($tenant, $invitationId, $actor, $requestId): IssuedAdminInvitation {
            $old = AdminInvitation::query()->where('tenant_id', $tenant->id)->whereKey($invitationId)->lockForUpdate()->firstOrFail();
            if ($old->status !== InvitationStatus::Pending) {
                throw new DomainException('INVITATION_NOT_PENDING', 'Only a pending invitation can be resent.');
            }

            $old->update(['status' => InvitationStatus::Cancelled, 'cancelled_at' => now()]);
            $issued = $this->issuer->execute($tenant, $old->email, $old->role, $actor);
            $this->audit->record($tenant->id, 'ADMIN', $actor->id, 'ADMIN_INVITATION_RESENT', 'admin_invitation', $issued->invitation->id, ['replaced_invitation_id' => $old->id], [
                'email' => $issued->invitation->email,
                'expires_at' => $issued->invitation->expires_at->toIso8601String(),
            ], $requestId);

            return $issued;
        });

        Mail::to($issued->invitation->email)->send(new AdminInvitationMail($tenant, $issued->invitation, $issued->url));

        return $issued;
    }
}
