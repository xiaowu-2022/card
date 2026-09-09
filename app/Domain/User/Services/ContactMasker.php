<?php

namespace App\Domain\User\Services;

use App\Domain\User\Enums\RegistrationChannel;

final class ContactMasker
{
    public function mask(RegistrationChannel $channel, string $destination): string
    {
        if ($channel === RegistrationChannel::Email) {
            [$local, $domain] = explode('@', $destination, 2);

            return mb_substr($local, 0, 1).'***@'.$domain;
        }

        return mb_substr($destination, 0, 3).'******'.mb_substr($destination, -3);
    }
}
