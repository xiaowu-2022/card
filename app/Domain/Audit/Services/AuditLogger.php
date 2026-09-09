<?php

namespace App\Domain\Audit\Services;

use App\Domain\Audit\Models\AuditLog;
use App\Support\Logging\SensitiveDataRedactor;

final class AuditLogger
{
    public function __construct(private readonly SensitiveDataRedactor $redactor) {}

    /** @param array<string, mixed>|null $before @param array<string, mixed>|null $after */
    public function record(
        ?string $tenantId,
        string $actorType,
        ?string $actorId,
        string $action,
        string $resourceType,
        ?string $resourceId,
        ?array $before = null,
        ?array $after = null,
        ?string $requestId = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'tenant_id' => $tenantId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'action' => $action,
            'resource_type' => $resourceType,
            'resource_id' => $resourceId,
            'before_data' => $before === null ? null : $this->redactor->redact($before),
            'after_data' => $after === null ? null : $this->redactor->redact($after),
            'request_id' => $requestId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
