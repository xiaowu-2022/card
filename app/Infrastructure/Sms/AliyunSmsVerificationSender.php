<?php

namespace App\Infrastructure\Sms;

use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Notification\Exceptions\SmsDeliveryUnknown;
use App\Domain\Notification\Models\PlatformSmsProfile;
use App\Domain\Notification\Services\TenantSmsPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Support\Errors\DomainException;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final readonly class AliyunSmsVerificationSender implements SmsVerificationSender
{
    private const HOST = 'dysmsapi.aliyuncs.com';

    public function __construct(private TenantSmsPolicy $policy, private AliyunAcs3Signer $signer) {}

    public function isAvailable(Tenant $tenant): bool
    {
        return $this->policy->settings($tenant->id)?->available() ?? false;
    }

    public function sendVerificationCode(Tenant $tenant, #[\SensitiveParameter] string $destination, #[\SensitiveParameter] string $code): void
    {
        $settings = $this->settings($tenant);
        $this->send($settings, $destination, $settings->verification_template_code, ['code' => $code]);
    }

    public function sendExistingAccountNotice(Tenant $tenant, #[\SensitiveParameter] string $destination): void
    {
        $settings = $this->settings($tenant);
        // Optional approved notification template with no variables. Never invent an OTP.
        if (filled($settings->existing_account_template_code)) {
            $this->send($settings, $destination, $settings->existing_account_template_code, []);
        }
    }

    private function settings(Tenant $tenant): PlatformSmsProfile
    {
        $settings = $this->policy->settings($tenant->id);
        if (! $settings?->available()) {
            throw new DomainException('SMS_TRANSPORT_UNAVAILABLE', 'Phone verification is not available for this company.', 503);
        }

        return $settings;
    }

    private function send(#[\SensitiveParameter] PlatformSmsProfile $settings, #[\SensitiveParameter] string $destination, string $template, #[\SensitiveParameter] array $parameters): void
    {
        // Only a single server-normalized E.164 destination. No client-selected host/URL.
        if (! preg_match('/^\+[1-9][0-9]{6,14}$/D', $destination)) {
            throw new DomainException('SMS_DESTINATION_INVALID', 'Enter a valid phone number.');
        }
        try {
            $keyId = $settings->access_key_id;
            $secret = $settings->access_key_secret;
        } catch (DecryptException) {
            throw new DomainException('SMS_TRANSPORT_UNAVAILABLE', 'Phone verification is not available for this company.', 503);
        }
        $query = ['PhoneNumbers' => ltrim($destination, '+'), 'SignName' => $settings->sign_name, 'TemplateCode' => $template];
        if ($parameters !== []) {
            $query['TemplateParam'] = json_encode($parameters, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        }
        $headers = [
            'host' => self::HOST,
            'x-acs-action' => 'SendSms',
            'x-acs-version' => '2017-05-25',
            'x-acs-date' => gmdate('Y-m-d\TH:i:s\Z'),
            'x-acs-signature-nonce' => (string) Str::uuid(),
            'x-acs-content-sha256' => hash('sha256', ''),
        ];
        $authorization = $this->signer->authorization('POST', '/', $query, $headers, $keyId, $secret);
        try {
            // Official RPC parameters are in the query. Never log this URL or HTTP exceptions.
            // SendSms is non-idempotent: no redirects, no retries, no provider calls in a DB transaction.
            $response = Http::connectTimeout(5)->timeout(15)->withoutRedirecting()
                ->withHeaders([...$headers, 'Authorization' => $authorization])
                ->send('POST', 'https://'.self::HOST.'/?'.$this->signer->queryString($query), ['body' => '']);
        } catch (\Throwable) {
            throw new SmsDeliveryUnknown;
        }
        $code = $response->json('Code');
        if ($response->successful() && $code === 'OK') {
            return;
        }
        if ($response->serverError() || $response->redirect() || ! is_string($code) || $code === '' || $code === 'OK') {
            throw new SmsDeliveryUnknown;
        }
        // Provider messages can contain phone numbers, keys and template contents. Never forward them.
        throw new DomainException('SMS_SEND_REJECTED', 'Unable to send the SMS code. Please try later or contact support.', 503);
    }
}
