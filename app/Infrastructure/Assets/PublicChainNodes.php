<?php

namespace App\Infrastructure\Assets;

/** Fixed public endpoints; custom endpoints still require the deployment allowlist. */
final class PublicChainNodes
{
    public const URLS = [
        'ETHEREUM' => 'https://ethereum-rpc.publicnode.com',
        'BITCOIN' => 'https://bitcoin-rpc.publicnode.com',
    ];

    public static function allowed(string $network, string $url): bool
    {
        if ($url === (self::URLS[$network] ?? null)) {
            return true;
        }

        return parse_url($url, PHP_URL_SCHEME) === 'https'
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_PASS) === null
            && parse_url($url, PHP_URL_QUERY) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null
            && in_array(parse_url($url, PHP_URL_HOST), config('assets.rpc_allowed_hosts', []), true);
    }
}
