<?php

use App\Support\Logging\RedactSensitiveLogContext;
use App\Support\Logging\SensitiveDataRedactor;
use Illuminate\Log\Logger;
use Monolog\Handler\TestHandler;
use Monolog\Logger as MonologLogger;

it('redacts SMTP tokens test recipients and transport transcripts', function (): void {
    expect((new SensitiveDataRedactor)->redact(['smtp_token' => 'test-token', 'test_email' => 'test@example.test', 'recipient_hash' => 'test-hash',
        'smtp_transcript' => 'AUTH and DATA', 'smtpDebug' => 'AUTH and DATA']))->each->toBe('[REDACTED]');
});

it('redacts Aliyun credentials OTP parameters and sensitive request query strings', function (): void {
    $redactor = new SensitiveDataRedactor;
    expect($redactor->redact(['AccessKeyId' => 'test-key', 'AccessKeySecret' => 'test-secret', 'PhoneNumbers' => '8613800138000', 'TemplateParam' => '{"code":"123456"}']))->each->toBe('[REDACTED]');
    expect($redactor->redactString('https://dysmsapi.aliyuncs.com/?PhoneNumbers=8613800138000&TemplateParam=%7Bcode%7D&TemplateCode=SMS_123'))
        ->toBe('https://dysmsapi.aliyuncs.com/?PhoneNumbers=[REDACTED]&TemplateParam=[REDACTED]&TemplateCode=SMS_123');
    expect($redactor->redactString('ACS3-HMAC-SHA256 Credential=test-key,SignedHeaders=host,Signature=test-signature'))->toBe('ACS3-HMAC-SHA256 [REDACTED]');
});

it('redacts sensitive values recursively', function (): void {
    $result = (new SensitiveDataRedactor)->redact([
        'email' => 'safe@example.test',
        'password' => 'password-value',
        'password_confirmation' => 'secret-value',
        'code' => '123456',
        'code_hash' => 'hashed-code',
        'verification_code' => '654321',
        'identity_number_encrypted' => 'encrypted-identity',
        'identity_hash' => 'identity-hash',
        'ocr_result' => ['candidate_identity_number' => 'ID-1234'],
        'document_url' => 'https://private.example/document?signature=secret',
        'signed_url' => 'https://private.example/signed',
        'secret' => 'provider-secret',
        'nested' => [
            'ProviderToken' => 'token-value',
            'PAN' => 'card-value',
            'cvv' => '123',
            'identity_number' => 'identity-value',
            'otp' => '123456',
            'apiKey' => 'key-value',
        ],
        'headers' => [
            'Authorization' => 'Bearer complete-token',
            'Cookie' => 'session=sensitive',
            'Set-Cookie' => 'session=sensitive',
        ],
    ]);

    expect($result)->toBe([
        'email' => '[REDACTED]',
        'password' => '[REDACTED]',
        'password_confirmation' => '[REDACTED]',
        'code' => '[REDACTED]',
        'code_hash' => '[REDACTED]',
        'verification_code' => '[REDACTED]',
        'identity_number_encrypted' => '[REDACTED]',
        'identity_hash' => '[REDACTED]',
        'ocr_result' => '[REDACTED]',
        'document_url' => '[REDACTED]',
        'signed_url' => '[REDACTED]',
        'secret' => '[REDACTED]',
        'nested' => [
            'ProviderToken' => '[REDACTED]',
            'PAN' => '[REDACTED]',
            'cvv' => '[REDACTED]',
            'identity_number' => '[REDACTED]',
            'otp' => '[REDACTED]',
            'apiKey' => '[REDACTED]',
        ],
        'headers' => [
            'Authorization' => '[REDACTED]',
            'Cookie' => '[REDACTED]',
            'Set-Cookie' => '[REDACTED]',
        ],
    ]);
});

it('redacts bearer credentials in otherwise safe log strings', function (): void {
    $redactor = new SensitiveDataRedactor;

    expect($redactor->redactString('Provider returned Authorization: Bearer abc.def.ghi'))
        ->toBe('Provider returned Authorization: Bearer [REDACTED]');
});

it('does not redact unrelated keys merely containing short character sequences', function (): void {
    expect((new SensitiveDataRedactor)->redact(['company_name' => 'Aperture']))
        ->toBe(['company_name' => 'Aperture']);
});

it('redacts every KYC spelling without broad id-key false positives', function (): void {
    $input = [
        'identityNumber' => 'secret', 'identity_hash' => 'secret', 'identityHash' => 'secret',
        'ocrResult' => ['candidate' => 'secret'], 'signedUrl' => 'secret', 'document_url' => 'secret',
        'frontObjectKey' => 'secret', 'back_object_key' => 'secret', 'application_id' => 'safe-id',
    ];
    $redacted = (new SensitiveDataRedactor)->redact($input);

    expect(array_values(array_diff_key($redacted, ['application_id' => true])))->each->toBe('[REDACTED]')
        ->and($redacted['application_id'])->toBe('safe-id');
});

it('redacts withdrawal destination secrets while preserving safe masks', function (): void {
    $redacted = app(SensitiveDataRedactor::class)->redact([
        'withdrawal_address' => 'T'.str_repeat('A', 33),
        'address_ciphertext' => 'encrypted-payload',
        'address_hash' => str_repeat('a', 64),
        'masked_address' => 'TAAAAA…AAAAA',
    ]);

    expect($redacted)->toBe([
        'withdrawal_address' => '[REDACTED]',
        'address_ciphertext' => '[REDACTED]',
        'address_hash' => '[REDACTED]',
        'masked_address' => 'TAAAAA…AAAAA',
    ]);
});

it('redacts per-card holder material envelopes fingerprints contacts and document payloads', function (): void {
    $input = array_fill_keys(['materials_encrypted', 'request_hash', 'front', 'back', 'certId', 'firstName', 'lastName',
        'legal_first_name', 'legal_last_name', 'dateOfBirth', 'email', 'mobile', 'phone', 'residentialAddress'], 'private-material');
    expect((new SensitiveDataRedactor)->redact($input))->each->toBe('[REDACTED]');
});

it('redacts context and bearer credentials at the logging processor boundary', function (): void {
    $handler = new TestHandler;
    $logger = new Logger(new MonologLogger('redaction-test', [$handler]));
    (new RedactSensitiveLogContext(new SensitiveDataRedactor))($logger);

    $logger->info('Request used Bearer complete-token', ['Authorization' => 'Bearer another-token']);
    $record = $handler->getRecords()[0];

    expect($record->message)->toBe('Request used Bearer [REDACTED]')
        ->and($record->context)->toBe(['Authorization' => '[REDACTED]']);
});
