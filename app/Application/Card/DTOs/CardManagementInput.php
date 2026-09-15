<?php

namespace App\Application\Card\DTOs;

final readonly class CardManagementInput
{
    /** @param array<string,string> $holderChanges */
    public function __construct(public string $action, public ?string $requestId, public ?string $orderId,
        public ?string $amount, #[\SensitiveParameter] public array $holderChanges) {}

    public static function fromValidated(#[\SensitiveParameter] array $values): self
    {
        $fields = array_intersect_key($values, array_flip(['email', 'date_of_birth', 'nationality_country_code', 'mobile', 'mobile_country_code',
            'residential_country_code', 'residential_state', 'residential_city', 'residential_address', 'residential_postal_code']));

        return new self($values['action'], $values['request_id'] ?? null, $values['order_id'] ?? null, $values['amount'] ?? null, $fields);
    }

    public function fields(): array
    {
        return ['action' => $this->action, 'request_id' => $this->requestId, 'order_id' => $this->orderId, 'amount' => $this->amount] + $this->holderChanges;
    }
}
