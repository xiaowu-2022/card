<?php

namespace App\Application\User;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

/** A short-lived private mailbox between one native document and its owning shell. */
final class ConsumerTokenBridge
{
    public function open(string $tenantId): string
    {
        $secret = Str::random(64);
        Cache::put($this->key($tenantId, $secret), ['revision' => 0], now()->addHours(2));

        return $secret;
    }

    public function publish(string $tenantId, #[\SensitiveParameter] string $secret, #[\SensitiveParameter] string $token): int
    {
        $key = $this->key($tenantId, $secret);

        return Cache::lock($key.':lock', 10)->block(3, function () use ($key, $token): int {
            $value = Cache::get($key);
            abort_unless(is_array($value), 410);
            $revision = $value['revision'] + 1;
            Cache::put($key.':credential', ['revision' => $revision, 'credential' => Crypt::encryptString($token)], now()->addMinute());
            Cache::put($key, ['revision' => $revision], now()->addDays(30));

            return $revision;
        });
    }

    public function claim(string $tenantId, #[\SensitiveParameter] string $secret, int $revision): string
    {
        $value = Cache::get($this->key($tenantId, $secret).':credential');
        // A lost native response may retry the same revision for one minute. A
        // newer account publication can never be mistaken for an older signal.
        abort_unless(is_array($value) && $value['revision'] === $revision, 410);

        return Crypt::decryptString($value['credential']);
    }

    private function key(string $tenantId, #[\SensitiveParameter] string $secret): string
    {
        return 'consumer-token-bridge:'.hash('sha256', $tenantId.':'.$secret);
    }
}
