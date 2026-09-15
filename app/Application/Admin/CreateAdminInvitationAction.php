<?php

namespace App\Application\Admin;

use App\Application\Admin\DTOs\IssuedAdminInvitation;
use App\Application\Tenant\CompanyConfigurationAuthority;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Models\Role;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Tenant\Models\Tenant;
use App\Mail\AdminInvitationMail;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

final readonly class CreateAdminInvitationAction
{
    public function __construct(
        private IssueAdminInvitationAction $issuer,
        private AuditLogger $audit,
    ) {}

    public function execute(Tenant $tenant, string $email, Role $role, AdminUser $inviter, ?string $requestId = null): IssuedAdminInvitation
    {
        app(CompanyConfigurationAuthority::class)->assert($inviter);
        $issued = DB::transaction(function () use ($tenant, $email, $role, $inviter, $requestId): IssuedAdminInvitation {
            $issued = $this->issuer->execute($tenant, $email, $role, $inviter);
            $this->audit->record($tenant->id, 'ADMIN', $inviter->id, 'ADMIN_INVITED', 'admin_invitation', $issued->invitation->id, null, [
                'email' => $issued->invitation->email,
                'role' => $role->name,
                'expires_at' => $issued->invitation->expires_at->toIso8601String(),
            ], $requestId);

            return $issued;
        });

        Mail::to($issued->invitation->email)->send(new AdminInvitationMail($tenant, $issued->invitation, $issued->url));

        return $issued;
    }
}
