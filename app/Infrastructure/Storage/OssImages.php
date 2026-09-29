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

    /** Five-minute V4 POST policy: one staging object, bounded bytes, fixed metadata. */
    public function directUploadPolicy(OssConfiguration $config, string $key, string $mime, int $maxBytes): array
    {
        $credentials = $config->credentials;
        $time = now()->utc();
        $date = $time->format('Ymd');
        $fields = [
            'key' => $key,
            'x-oss-signature-version' => 'OSS4-HMAC-SHA256',
            'x-oss-credential' => $credentials['access_key_id'].'/'.$date.'/'.$config->region.'/oss/aliyun_v4_request',
            'x-oss-date' => $time->format('Ymd\THis\Z'),
            'x-oss-object-acl' => 'private',
            'x-oss-server-side-encryption' => 'AES256',
            'x-oss-forbid-overwrite' => 'true',
            'Cache-Control' => 'no-store',
            'x-oss-content-type' => $mime,
            'success_action_status' => '204',
        ];
        $conditions = [['bucket' => $config->bucket], ['content-length-range', 1, $maxBytes]];
        foreach ($fields as $name => $value) {
            $conditions[] = [$name => $value];
        }
        $policy = base64_encode(json_encode(['expiration' => $time->copy()->addMinutes(5)->format('Y-m-d\TH:i:s.000\Z'), 'conditions' => $conditions], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $signingKey = hash_hmac('sha256', $date, 'aliyun_v4'.$credentials['access_key_secret'], true);
        foreach ([$config->region, 'oss', 'aliyun_v4_request'] as $part) {
            $signingKey = hash_hmac('sha256', $part, $signingKey, true);
        }
        $fields['policy'] = $policy;
        $fields['x-oss-signature'] = hash_hmac('sha256', $policy, $signingKey);
        // The server endpoint may be internal; phones always upload through a public address.
        $url = $config->endpoint === $config->public_url ? $config->public_url
            : 'https://'.$config->bucket.'.oss-'.$config->region.'.aliyuncs.com';

        return ['url' => $url, 'fields' => $fields, 'maxBytes' => $maxBytes];
    }

    /** @return array{size:int,etag:string} */
    public function metadata(OssConfiguration $config, string $key): array
    {
        try {
            $headers = array_change_key_case($this->client($config)->getObjectMeta($config->bucket, $key), CASE_LOWER);
            if (! isset($headers['content-length'], $headers['etag'])) {
                throw new \RuntimeException;
            }

            return ['size' => (int) $headers['content-length'], 'etag' => $headers['etag']];
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function copyDirectImage(OssConfiguration $config, string $source, string $destination, string $etag, string $mime): void
    {
        try {
            $this->client($config)->copyObject($config->bucket, $source, $config->bucket, $destination, [OssClient::OSS_HEADERS => [
                'x-oss-copy-source-if-match' => $etag, 'x-oss-metadata-directive' => 'REPLACE',
                'Content-Type' => $mime, 'Cache-Control' => 'no-store',
                'x-oss-object-acl' => 'private', 'x-oss-server-side-encryption' => 'AES256',
            ]]);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    public function publishDirectImage(OssConfiguration $config, string $key): void
    {
        try {
            $this->client($config)->putObjectAcl($config->bucket, $key, 'public-read');
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
    }

    /** Read at most maxBytes + 1; no image processing or local temporary file. */
    public function getBounded(OssConfiguration $config, string $key, int $maxBytes): string
    {
        try {
            return $this->client($config)->getObject($config->bucket, $key, [OssClient::OSS_RANGE => '0-'.$maxBytes]);
        } catch (\Throwable) {
            throw new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503);
        }
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
