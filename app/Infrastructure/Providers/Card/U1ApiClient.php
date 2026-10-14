<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Infrastructure\Assets\ExactJson;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** U1's documented protocol. Transport only: no application balance or business writes. */
final readonly class U1ApiClient
{
    // Existing directory configuration remains valid; wire protocol selection is explicit.
    public const BASE_URL = 'https://api.uucard.cyou/v1';

    public const V2_BASE_URL = 'https://api.uucard.cyou/v2';

    private const ENDPOINTS = [
        'queryaccount' => [], 'querygroupno' => [],
        'vccadd' => ['currency', 'cardNo'], 'vccpin' => ['bin', 'cardNo'],
        'vccquery' => ['cardNo'], 'vccopen' => ['currency', 'amount', 'groupNo'],
        'vccrechage' => ['currency', 'amount', 'cardNo'], 'vccwithdraw' => ['amount', 'cardNo'],
        'vccemail' => ['cardNo', 'email'], 'vcctrade' => ['cardNo'],
        'vccquerylist' => [], 'vccrechargelist' => [], 'vccquerycvv' => ['cardNo'],
        'queryapiid' => ['apiId'], 'querycvv' => ['cardNo'],
        'queryrequestid' => ['requestId'], 'notifytest' => ['editType'],
    ];

    public function __construct(private string $customerNo, #[\SensitiveParameter] private string $apiKey, private string $baseUrl = self::BASE_URL, private bool $v2 = true) {}

    public function call(string $endpoint, #[\SensitiveParameter] array $fields = []): array
    {
        if ($this->baseUrl !== self::BASE_URL || $this->customerNo === '' || $this->apiKey === '' || ! isset(self::ENDPOINTS[$endpoint])) {
            throw new ProviderUnavailableException('U1 connection is unavailable.');
        }
        foreach (self::ENDPOINTS[$endpoint] as $field) {
            if (! is_string($fields[$field] ?? null) || $fields[$field] === '') {
                throw new ProviderUnavailableException('U1 request fields are invalid.');
            }
        }
        foreach ($fields as $value) {
            if (! is_string($value)) {
                throw new ProviderUnavailableException('U1 request fields must be strings.');
            }
        }
        if ($this->v2) {
            $fields['requestId'] ??= (string) Str::uuid();
            if (! preg_match('/^[a-zA-Z0-9._-]{1,120}$/D', $fields['requestId'])) {
                throw new ProviderUnavailableException('U1 request ID is invalid.');
            }
        }
        $timestamp = (string) now()->getTimestampMs();
        $order = $this->v2 && $endpoint === 'notifytest' ? [] : self::ENDPOINTS[$endpoint];
        $parts = array_map(fn ($field) => $fields[$field], $order);
        $body = $fields + ['customerNo' => $this->customerNo, 'timestamp' => $timestamp];
        // Callers cannot override authentication or time fields.
        $body['customerNo'] = $this->customerNo;
        $body['timestamp'] = $timestamp;
        $body['sign'] = md5($this->customerNo.implode('', $parts).$timestamp.$this->apiKey);
        try {
            $response = Http::connectTimeout(5)->timeout(20)->withoutRedirecting()->post(($this->v2 ? self::V2_BASE_URL : self::BASE_URL).'/api/'.$endpoint, $body);
            if (! $response->successful() || strlen($response->body()) > 1048576) {
                throw new \RuntimeException;
            }
            $data = ExactJson::decode($response->body());
            if (! is_string($data['resultCode'] ?? null) || ! is_string($data['timestamp'] ?? null)
                || ! preg_match('/^\d{10,16}$/D', $data['timestamp'])
                || ! is_string($data['sign'] ?? null) || ! preg_match('/^[a-fA-F0-9]{32}$/D', $data['sign'])
                || ! hash_equals(md5($data['resultCode'].$data['timestamp'].$this->apiKey), strtolower($data['sign']))) {
                throw new \RuntimeException;
            }
        } catch (\Throwable) {
            // No retries and no transport exception details containing credentials, PAN, PIN or OTP.
            throw new ProviderUnknownResultException('U1 request result could not be confirmed.');
        }
        if ($data['resultCode'] === '0002') {
            throw new ProviderRejectedException('U1 rejected the request.');
        }
        if (! in_array($data['resultCode'], ['0000', '0001', '0003'], true)) {
            throw new ProviderUnknownResultException('U1 returned an unknown request result.');
        }

        return $data;
    }

    public function account(): array
    {
        return $this->call('queryaccount');
    }

    public function groups(): array
    {
        return $this->call('querygroupno');
    }

    public function addPhysicalCard(string $currency, string $cardNo, string $cardName = ''): array
    {
        return $this->call('vccadd', compact('currency', 'cardNo', 'cardName'));
    }

    public function changePin(string $cardNo, #[\SensitiveParameter] string $pin): array
    {
        if (! preg_match('/^\d{6}$/D', $pin)) {
            throw new ProviderUnavailableException('Enter a six-digit PIN.');
        }

        return $this->call('vccpin', ['cardNo' => $cardNo, 'bin' => $pin]);
    }

    public function card(string $cardNo): array
    {
        return $this->call('vccquery', compact('cardNo'));
    }

    public function openCard(string $currency, string $amount, string $groupNo, string $cardName = '', ?string $requestId = null): array
    {
        return $this->call('vccopen', compact('currency', 'amount', 'groupNo', 'cardName') + ($requestId === null ? [] : ['requestId' => $requestId]));
    }

    public function recharge(string $currency, string $amount, string $cardNo, ?string $requestId = null): array
    {
        return $this->call('vccrechage', compact('currency', 'amount', 'cardNo') + ($requestId === null ? [] : ['requestId' => $requestId]));
    }

    public function withdraw(string $amount, string $cardNo, ?string $requestId = null): array
    {
        return $this->call('vccwithdraw', compact('amount', 'cardNo') + ($requestId === null ? [] : ['requestId' => $requestId]));
    }

    public function changeEmail(string $cardNo, string $email): array
    {
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new ProviderUnavailableException('Enter a valid email address.');
        }

        return $this->call('vccemail', compact('cardNo', 'email'));
    }

    public function transactions(string $cardNo, int $start = 0, int $size = 20): array
    {
        if ($start < 0 || $size < 1 || $size > 100) {
            throw new ProviderUnavailableException('Invalid transaction page.');
        }

        return $this->call('vcctrade', ['cardNo' => $cardNo, 'start' => (string) $start, 'size' => (string) $size]);
    }

    public function cards(): array
    {
        return $this->call('vccquerylist');
    }

    public function recharges(): array
    {
        return $this->call('vccrechargelist');
    }

    public function cardIframe(string $cardNo): array
    {
        $result = $this->call('vccquerycvv', compact('cardNo'));
        $url = $result['data']['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (! $parts || ($parts['scheme'] ?? null) !== 'https' || ($parts['host'] ?? null) !== 'api.uucard.cyou'
            || ($parts['port'] ?? 443) !== 443 || isset($parts['user']) || isset($parts['pass'])) {
            throw new ProviderUnknownResultException('U1 card display URL is unavailable.');
        }

        return $result;
    }

    public function operation(string $apiId): array
    {
        return $this->call('queryapiid', compact('apiId'));
    }

    public function sensitiveCard(string $cardNo): array
    {
        return $this->call('querycvv', compact('cardNo'));
    }

    public function requestIds(string $requestId): array
    {
        return $this->call('queryrequestid', compact('requestId'));
    }

    public function testNotification(string $editType): array
    {
        if (! isset(U1NotificationVerifier::FIELDS[$editType])) {
            throw new ProviderUnavailableException('Unsupported U1 notification type.');
        }

        return $this->call('notifytest', compact('editType'));
    }
}
