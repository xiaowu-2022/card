<?php

namespace App\Infrastructure\Assets;

use App\Domain\Assets\ChainConnection;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Http;

final class ChainRpc
{
    public function call(ChainConnection $connection, string $method, array $params = []): mixed
    {
        // Operators explicitly allow node hostnames in deployment configuration.
        $url = $connection->rpc_url;
        $host = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        if (! $connection->enabled || ! $host || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_USER) !== null || ! in_array($host, config('assets.rpc_allowed_hosts', []), true)) {
            throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
        }
        try {
            $http = Http::connectTimeout(5)->timeout(30)->withoutRedirecting();
            $credential = $connection->credential ?? [];
            if (isset($credential['username'], $credential['password'])) {
                $http = $http->withBasicAuth($credential['username'], $credential['password']);
            }
            if (isset($credential['api_key'])) {
                $http = $http->withHeaders(['X-API-Key' => $credential['api_key']]);
            }
            $response = $http->post($url, ['jsonrpc' => '2.0', 'id' => 'assets', 'method' => $method, 'params' => $params]);
            if (! $response->successful()) {
                throw new \UnexpectedValueException;
            }
            $data = ExactJson::decode($response->body());
            if (isset($data['error']) || ! array_key_exists('result', $data)) {
                throw new \UnexpectedValueException;
            }

            return $data['result'];
        } catch (\Throwable) {
            // Never forward node errors or credential-bearing URLs to logs/UI.
            throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
        }
    }
}
