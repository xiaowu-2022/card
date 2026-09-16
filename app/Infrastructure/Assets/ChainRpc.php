<?php

namespace App\Infrastructure\Assets;

use App\Domain\Assets\ChainConnection;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class ChainRpc
{
    public function call(ChainConnection $connection, string $method, array $params = []): mixed
    {
        $url = $connection->rpc_url ?: (PublicChainNodes::URLS[$connection->network] ?? '');
        if (! $connection->enabled || ! PublicChainNodes::allowed($connection->network, $url)) {
            throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
        }
        try {
            $http = Http::connectTimeout(5)->timeout(30)->withoutRedirecting()->withUserAgent('ApertureCards/1.0 (read-only chain verification)')->withHeaders(['Accept-Encoding' => 'gzip']);
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
                Log::warning('assets.chain.request_failed', ['network' => $connection->network, 'method' => $method, 'http_status' => $response->status()]);
                throw new DomainException('CHAIN_UNAVAILABLE', match ($response->status()) {
                    401, 403 => 'The node denied this query. A supported node or access credential is required.',
                    429 => 'The node is rate limited. Please retry later.',
                    default => 'The node is temporarily unavailable. Please retry later.',
                }, 503);
            }
            $data = ExactJson::decode($response->body());
            if (isset($data['error'])) {
                $code = (string) ($data['error']['code'] ?? '');
                Log::warning('assets.chain.rpc_failed', ['network' => $connection->network, 'method' => $method, 'rpc_code' => preg_match('/^-?\d{1,8}$/', $code) ? $code : null]);
                throw new DomainException('CHAIN_UNAVAILABLE', $code === '-32601'
                    ? 'The node is reachable but does not support complete transfer verification. Use a compatible node.'
                    : 'The node could not provide the required transfer evidence. Check its query permissions.', 503);
            }
            if (! array_key_exists('result', $data)) {
                throw new \UnexpectedValueException;
            }

            return $data['result'];
        } catch (DomainException $e) {
            throw $e;
        } catch (\JsonException|\UnexpectedValueException) {
            Log::warning('assets.chain.invalid_response', ['network' => $connection->network, 'method' => $method]);
            throw new DomainException('CHAIN_UNAVAILABLE', 'The node returned incomplete or invalid data. Verification was not completed.', 503);
        } catch (\Throwable) {
            Log::warning('assets.chain.connection_failed', ['network' => $connection->network, 'method' => $method]);
            // Never forward node errors or credential-bearing URLs to logs/UI.
            throw new DomainException('CHAIN_UNAVAILABLE', 'The network connection is unavailable.', 503);
        }
    }
}
