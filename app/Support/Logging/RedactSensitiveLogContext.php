<?php

namespace App\Support\Logging;

use Illuminate\Log\Logger;
use Monolog\LogRecord;

final readonly class RedactSensitiveLogContext
{
    public function __construct(private SensitiveDataRedactor $redactor) {}

    public function __invoke(Logger $logger): void
    {
        foreach ($logger->getLogger()->getHandlers() as $handler) {
            $handler->pushProcessor(fn (LogRecord $record): LogRecord => $record->with(
                message: $this->redactor->redactString($record->message),
                context: $this->redactor->redact($record->context),
                extra: $this->redactor->redact($record->extra),
            ));
        }
    }
}
