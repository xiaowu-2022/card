<?php

namespace App\Infrastructure\Assets;

use App\Domain\Assets\ChainConnection;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Http;

final class ChainRpc
{
    public function call(ChainConnection $connection, string $method, array $params = []): mixed
    {
        $url = $connection->rpc_url ?: (PublicChainNodes::URLS[$connection->network] ?? '');
        if (! $connection->enabled || ! PublicChainNodes::allowed($connection->network, $url)) {
            throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
        }
        try {
            $http = Http::connectTimeout(5)->timeout(30)->withoutRedirecting();
            // Never forward custom credentials to a public endpoint.
            $credential = $url === (PublicChainNodes::URLS[$connection->network] ?? null) ? [] : ($connection->credential ?? []);
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
