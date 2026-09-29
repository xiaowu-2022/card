<?php

namespace App\Infrastructure\Storage;

use App\Application\Media\ServerImages;
use App\Domain\Media\OssConfiguration;
use App\Support\Errors\DomainException;
use OSS\Core\OssException;
use OSS\Credentials\StaticCredentialsProvider;
use OSS\OssClient;

class OssImages
{
    private bool $connectionTest = false;

    public function forConnectionTest(): static
    {
        $client = clone $this;
        $client->connectionTest = true;

        return $client;
    }

    private function uploadFailure(\Throwable $error): DomainException
    {
        // Only fixed classifications leave the SDK: never URLs, request bodies or credentials.
        $reason = 'unknown';
        if ($error instanceof OssException) {
            $reason = match ($error->getErrorCode()) {
                'AccessDenied', 'AccessDeniedException' => 'permission',
                'InvalidAccessKeyId' => 'access_key',
                'SignatureDoesNotMatch', 'InvalidSignature', 'AuthorizationHeaderMalformed' => 'signature',
                'RequestTimeTooSkewed', 'RequestExpired' => 'clock',
                'NoSuchBucket' => 'bucket',
                'MethodNotAllowed', 'NotImplemented' => 'method',
                default => match ((int) $error->getHTTPStatus()) {
                    401, 403 => 'permission',
                    405, 501 => 'method',
                    301, 302, 307, 308 => 'redirect',
                    default => 'unknown',
                },
            };
        }
        if ($reason === 'unknown') {
            $message = strtolower($error->getMessage());
            $reason = match (true) {
                str_contains($message, 'timed out'), str_contains($message, 'timeout') => 'timeout',
                str_contains($message, 'could not resolve'), str_contains($message, "couldn't resolve") => 'dns',
                str_contains($message, 'certificate'), str_contains($message, 'ssl connect'), str_contains($message, 'tls') => 'tls',
                str_contains($message, 'failed to connect'), str_contains($message, "couldn't connect"), str_contains($message, 'connection refused') => 'connect',
                default => 'unknown',
            };
        }

        return new DomainException('IMAGE_STORAGE_UNAVAILABLE', 'Image storage is unavailable. Please try again.', 503, ['reason' => $reason]);
    }

    protected function client(OssConfiguration $config): OssClient
    {
        if (ServerImages::enabled() && ! $this->connectionTest) {
            throw new \RuntimeException('OSS disabled while server storage is active');
        }
        $credentials = $config->credentials;
        $client = new OssClient(['provider' => new StaticCredentialsProvider($credentials['access_key_id'], $credentials['access_key_secret']),
            'endpoint' => $config->endpoint, 'cname' => $config->endpoint === $config->public_url, 'region' => $config->region, 'signatureVersion' => OssClient::OSS_SIGNATURE_VERSION_V4]);
        $client->setTimeout(8);
        $client->setConnectTimeout(3);
        $client->setMaxTries(1);

        return $client;
    }

    /** Five-minute V4 POST policy: one staging object, bounded bytes, fixed metadata. */
    public function directUploadPolicy(OssConfiguration $config, string $key, string $mime, int $maxBytes, bool $publicRead = false): array
    {
        $credentials = $config->credentials;
        $time = now()->utc();
        $date = $time->format('Ymd');
        $fields = [
            'key' => $key,
            'x-oss-signature-version' => 'OSS4-HMAC-SHA256',
            'x-oss-credential' => $credentials['access_key_id'].'/'.$date.'/'.$config->region.'/oss/aliyun_v4_request',
            'x-oss-date' => $time->format('Ymd\THis\Z'),
            'x-oss-object-acl' => $publicRead ? 'public-read' : 'private',
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
            // Business images fall back after the short client timeout; offline asset publication can take longer.
            if (str_starts_with($key, 'assets/')) {
                $client->setTimeout(600);
            }
            $client->putObject($config->bucket, $key, $contents, [OssClient::OSS_HEADERS => [
                'Content-Type' => $mime, 'Cache-Control' => str_starts_with($key, 'assets/') ? 'public, max-age=31536000, immutable' : 'no-store', 'x-oss-object-acl' => 'public-read', 'x-oss-server-side-encryption' => 'AES256',
            ]]);
        } catch (\Throwable $error) {
            throw $this->uploadFailure($error);
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
