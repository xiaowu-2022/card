<?php

namespace App\Infrastructure\Sms;

final class AliyunAcs3Signer
{
    /** @param array<string,string> $query @param array<string,string> $headers */
    public function authorization(string $method, string $path, array $query, array $headers, #[\SensitiveParameter] string $keyId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $body = ''): string
    {
        $headers = array_change_key_case($headers, CASE_LOWER);
        ksort($headers, SORT_STRING);
        $canonicalHeaders = '';
        foreach ($headers as $name => $value) {
            $canonicalHeaders .= $name.':'.trim($value)."\n";
        }
        $signedHeaders = implode(';', array_keys($headers));
        $canonical = $method."\n".$path."\n".$this->queryString($query)."\n".$canonicalHeaders."\n".$signedHeaders."\n".hash('sha256', $body);
        $signature = hash_hmac('sha256', "ACS3-HMAC-SHA256\n".hash('sha256', $canonical), $secret);

        return 'ACS3-HMAC-SHA256 Credential='.$keyId.',SignedHeaders='.$signedHeaders.',Signature='.$signature;
    }

    /** @param array<string,string> $query */
    public function queryString(#[\SensitiveParameter] array $query): string
    {
        ksort($query, SORT_STRING);

        return http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }
}
