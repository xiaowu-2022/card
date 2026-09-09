<?php

use App\Domain\Audit\Services\AuditLogger;

it('records append-only audit events', function (): void {
    $log = app(AuditLogger::class)->record(null, 'SYSTEM', null, 'BOOTSTRAP_VERIFIED', 'system', null, null, ['phase' => '0']);
    expect($log->after_data)->toBe(['phase' => '0']);

    $log->action = 'CHANGED';
    expect(fn () => $log->save())->toThrow(LogicException::class, 'append-only');
});

it('redacts sensitive values before writing audit data', function (): void {
    $log = app(AuditLogger::class)->record(null, 'SYSTEM', null, 'SAFE_AUDIT', 'system', null, null, [
        'provider_token' => 'must-not-persist',
        'result' => 'safe',
    ]);

    expect($log->after_data)->toBe(['provider_token' => '[REDACTED]', 'result' => 'safe']);
});
