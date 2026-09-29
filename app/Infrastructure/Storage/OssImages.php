<?php

namespace App\Infrastructure\Storage;

use App\Domain\Media\OssConfiguration;
use App\Support\Errors\DomainException;
use OSS\Credentials\StaticCredentialsProvider;
use OSS\OssClient;

class OssImages
{
    protected function client(OssConfiguration $config): OssClient
    {
        $credentials = $config->credentials;
        $client = new OssClient(['provider' => new StaticCredentialsProvider($credentials['access_key_id'], $credentials['access_key_secret']),
            'endpoint' => $config->endpoint, 'cname' => $config->endpoint === $config->public_url, 'region' => $config->region, 'signatureVersion' => OssClient::OSS_SIGNATURE_VERSION_V4]);
        $client->setTimeout(30);
        $client->setConnectTimeout(10);
        $client->setMaxTries(1);

        return $client;
    }

    public function put(OssConfiguration $config, string $key, #[\SensitiveParameter] string $contents, string $mime): void
    {
        try {
            $client = $this->client($config);
            // Large validated images can exceed 30 seconds on a slow uplink.
            // Keep connection establishment bounded and retain the small-file timeout.
            if (str_starts_with($key, 'assets/')) {
                $client->setTimeout(600);
            } elseif (strlen($contents) > 1024 * 1024) {
                $client->setTimeout(180);
            }
            $client->putObject($config->bucket, $key, $contents, [OssClient::OSS_HEADERS => [
                'Content-Type' => $mime, 'Cache-Control' => str_starts_with($key, 'assets/') ? 'public, max-age=31536000, immutable' : 'no-store', 'x-oss-object-acl' => 'public-read', 'x-oss-server-side-encryption' => 'AES256',
            ]]);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function get(OssConfiguration $config, string $key): string
    {
        try {
            $client = $this->client($config);
            $client->setTimeout(str_starts_with($key, 'assets/') ? 600 : 180);

            return $client->getObject($config->bucket, $key);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function display(OssConfiguration $config, string $key, string $process): string
    {
        try {
            return $this->client($config)->getObject($config->bucket, $key, [OssClient::OSS_PROCESS => $process]);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function delete(OssConfiguration $config, string $key): void
    {
        try {
            $this->client($config)->deleteObject($config->bucket, $key);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function url(OssConfiguration $config, string $key): string
    {
        return rtrim($config->public_url, '/').'/'.implode('/', array_map('rawurlencode', explode('/', $key)));
    }
}
