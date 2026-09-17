<?php

namespace App\Infrastructure\Providers\Card;

use Brick\Math\BigDecimal;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/** Read-only merchant reporting. Never participates in card routing or accounting. */
final class PhotonPayMerchantReport
{
    public function read(string $encryptedConnection): array
    {
        return $this->fetch($encryptedConnection);
    }

    public function bins(string $encryptedConnection, bool $fresh = false): ?array
    {
        return $this->fetch($encryptedConnection, true, $fresh)['bins'] ?? null;
    }

    private function fetch(string $encryptedConnection, bool $bins = false, bool $fresh = false): array
    {
        try {
            $connection = json_decode(Crypt::decryptString($encryptedConnection), true, 512, JSON_THROW_ON_ERROR);
            $base = $connection['base_url'] ?? '';
            $sandbox = $base === 'https://x-api.sandbox.photontech.cc';
            if ((! $sandbox && $base !== 'https://x-api.photonpay.com') || ($sandbox && ! app()->environment('local', 'testing'))
                || ! is_string($connection['app_id'] ?? null) || empty($connection['app_id'])
                || ! is_string($connection['app_secret'] ?? null) || empty($connection['app_secret'])) {
                throw new RuntimeException('Reporting configuration unavailable.');
            }
            // Cache only sanitized metrics and encrypted tokens; never cache card response bodies.
            $cache = Cache::store(app()->environment('testing') ? 'array' : 'file');
            $key = 'photonpay-report:'.hash('sha256', $encryptedConnection);

            $reportKey = $key.($bins ? ':bins-v2' : '');
            if ($fresh) {
                $cache->forget($reportKey);
            }

            return $cache->remember($reportKey, 60, function () use ($cache, $key, $connection, $base, $sandbox, $bins): array {
                return $cache->lock($key.':lock', 60)->block(5, function () use ($cache, $key, $connection, $base, $sandbox, $bins): array {
                    $token = $cache->get($key.':token');
                    if (! is_string($token)) {
                        $auth = $this->decode(Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->withHeaders([
                            'Authorization' => 'basic '.base64_encode($connection['app_id'].'/'.$connection['app_secret']),
                            'Content-Type' => 'application/json', 'Accept' => 'application/json',
                        ])->withBody('', 'application/json')->post($base.'/oauth2/token/accessToken'));
                        $value = $auth['data']['token'] ?? null;
                        $expiry = $auth['data']['expiresIn'] ?? null;
                        if (! is_string($value) || $value === '' || ! is_string($expiry) || ! ctype_digit($expiry) || strlen($expiry) > 13) {
                            throw new RuntimeException('Reporting authentication unavailable.');
                        }
                        $ttl = min(6600, intdiv((int) $expiry, 1000) - time() - 30);
                        if ($ttl <= 0) {
                            throw new RuntimeException('Reporting token expired.');
                        }
                        $token = Crypt::encryptString($value);
                        $cache->put($key.':token', $token, $ttl);
                    }
                    try {
                        $headers = ['X-PD-TOKEN' => Crypt::decryptString($token), 'Accept' => 'application/json'];
                        if ($bins) {
                            $rows = $this->decode(Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->withHeaders($headers)
                                ->get($base.'/vcc/openApi/v4/getCardBin', ['cardCurrency' => 'USD', 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card']))['data'] ?? null;
                            if (! is_array($rows) || ! array_is_list($rows)) {
                                throw new RuntimeException('BIN catalog unavailable.');
                            }
                            $options = [];
                            foreach ($rows as $row) {
                                if (! is_array($row) || ! is_string($row['cardBin'] ?? null)
                                    || ! preg_match('/^[A-Za-z0-9._-]{4,64}$/D', $row['cardBin'])) {
                                    throw new RuntimeException('BIN catalog invalid.');
                                }
                                foreach (['cardCurrency', 'cardType', 'cardFormFactor', 'cardScheme'] as $field) {
                                    if (! is_string($row[$field] ?? null)) {
                                        throw new RuntimeException('BIN catalog invalid.');
                                    }
                                }
                                if (in_array('USD', array_map('trim', explode(',', $row['cardCurrency'])), true)
                                    && in_array('recharge', array_map('trim', explode(',', $row['cardType'])), true)
                                    && in_array('virtual_card', array_map('trim', explode(',', $row['cardFormFactor'])), true)) {
                                    if (! preg_match('/^[A-Za-z0-9 -]{1,32}$/D', $row['cardScheme'])) {
                                        throw new RuntimeException('BIN card scheme invalid.');
                                    }
                                    $option = ['bin' => $row['cardBin'], 'scheme' => $row['cardScheme']];
                                    if (isset($options[$row['cardBin']]) && $options[$row['cardBin']] !== $option) {
                                        throw new RuntimeException('BIN card scheme ambiguous.');
                                    }
                                    $options[$row['cardBin']] = $option;
                                }
                            }

                            return ['bins' => array_values($options)];
                        }
                        $account = $this->decode(Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->withHeaders($headers)
                            ->get($base.'/wallet/openApi/v4/account/single', ['currency' => 'USD', 'accountType' => 'FT10001']))['data'] ?? [];
                        $amount = $account['realTimeBalance'] ?? null;
                        $member = $account['memberId'] ?? null;
                        if (($account['currency'] ?? null) !== 'USD' || ($account['accountType'] ?? null) !== 'FT10001'
                            || ! is_string($amount) || ! preg_match('/^-?\d{1,12}(?:\.\d{1,8})?$/D', $amount)
                            || ! is_string($member) || ! preg_match('/^[A-Za-z0-9_-]{1,80}$/D', $member)) {
                            throw new RuntimeException('Reporting account response unavailable.');
                        }
                        $balance = (string) BigDecimal::of($amount)->toScale(8);
                        // Pin the card query to the same merchant as the authoritative account.
                        $cards = $this->decode(Http::connectTimeout(5)->timeout(12)->withoutRedirecting()->withHeaders($headers)
                            ->get($base.'/vcc/openApi/v4/pagingVccCard', ['pageIndex' => 1, 'pageSize' => 1, 'memberId' => $member]));
                        $count = $cards['total'] ?? null;
                        if (! is_string($count) || ! preg_match('/^\d{1,15}$/D', $count)
                            || ($cards['pageIndex'] ?? null) !== '1' || ($cards['pageSize'] ?? null) !== '1'
                            || ! is_array($cards['data'] ?? null) || ! array_is_list($cards['data'])
                            || count($cards['data']) !== min(1, (int) $count)) {
                            throw new RuntimeException('Reporting card count unavailable.');
                        }

                        return ['balance' => $balance, 'asset' => 'USD', 'issuedCardCount' => $count,
                            'queriedAt' => now()->toIso8601String(), 'sandbox' => $sandbox];
                    } catch (Throwable $exception) {
                        $cache->forget($key.':token');
                        throw $exception;
                    }
                });
            });
        } catch (Throwable) {
            // No raw provider errors, private data, credentials, or manual-value fallback.
            return ['balance' => null, 'asset' => 'USD', 'issuedCardCount' => null, 'queriedAt' => null, 'sandbox' => $sandbox ?? false];
        }
    }

    private function decode(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Reporting request unavailable.');
        }
        $data = (new PhotonPayTransactionNormalizer)->decode($response->body());
        if (($data['code'] ?? null) !== '0000') {
            throw new RuntimeException('Reporting response unavailable.');
        }

        return $data;
    }
}
