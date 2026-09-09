<?php

namespace App\Infrastructure\Sms;

use App\Domain\Notification\Contracts\SmsVerificationSender;
use App\Domain\Tenant\Models\Tenant;

final class FakeSmsVerificationSender implements SmsVerificationSender
{
    /** @var list<array{tenant_id:string,destination:string,code:?string,type:string}> */
    private array $messages = [];

    public function sendVerificationCode(Tenant $tenant, string $destination, string $code): void
    {
        $this->messages[] = ['tenant_id' => $tenant->id, 'destination' => $destination, 'code' => $code, 'type' => 'verification'];
    }

    public function sendExistingAccountNotice(Tenant $tenant, string $destination): void
    {
        $this->messages[] = ['tenant_id' => $tenant->id, 'destination' => $destination, 'code' => null, 'type' => 'existing-account'];
    }

    /** @return list<array{tenant_id:string,destination:string,code:?string,type:string}> */
    public function messages(): array
    {
        return $this->messages;
    }
}
