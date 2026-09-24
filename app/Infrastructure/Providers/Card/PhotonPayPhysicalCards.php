<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;

trait PhotonPayPhysicalCards
{
    public function forFormFactor(string $formFactor): self
    {
        if (! in_array($formFactor, ['virtual_card', 'physical_card'], true)) {
            throw new \InvalidArgumentException('Invalid card form factor.');
        }

        return new self($this->baseUrl, $this->appId, $this->appSecret, $this->privateKey, $this->accountId,
            $this->memberId, $this->matrixAccount, $this->timeoutSeconds, $this->cards, $this->tokenAuthentication, $formFactor, $this->tokenNamespace);
    }

    public function addRecipient(#[\SensitiveParameter] array $fields): string
    {
        $data = $this->managementCall('POST', '/vcc/openApi/v4/addRecipient', array_filter([
            'memberId' => $this->memberId, 'matrixAccount' => $this->matrixAccount,
        ], fn ($v) => $v !== null && $v !== '') + $fields);
        $id = $data['recipientId'] ?? null;
        if (! is_string($id) || ! preg_match('/^[A-Za-z0-9_-]{1,180}$/D', $id)) {
            throw new ProviderUnknownResultException('Recipient creation could not be confirmed.');
        }

        return $id;
    }

    public function recipientAvailable(string $recipientId): bool
    {
        $response = $this->managementCall('GET', '/vcc/openApi/v4/pagingRecipient', array_filter([
            'recipientId' => $recipientId, 'memberId' => $this->memberId, 'matrixAccount' => $this->matrixAccount,
            'pageIndex' => 1, 'pageSize' => 2,
        ], fn ($v) => $v !== null && $v !== ''), envelope: true);
        $rows = $response['data'] ?? null;

        return is_array($rows) && count($rows) === 1 && ($rows[0]['recipientId'] ?? null) === $recipientId
            && in_array($rows[0]['recipientStatus'] ?? null, ['Normal', 'normal'], true)
            && (! $this->memberId || ($rows[0]['memberId'] ?? null) === $this->memberId);
    }

    public function activatePhysicalCard(string $cardId, string $expiry, #[\SensitiveParameter] string $pin, #[\SensitiveParameter] string $confirmation): void
    {
        $this->assertLiveReference($cardId);
        $this->managementCall('POST', '/vcc/openApi/v4/activateCard', [
            'cardId' => $cardId, 'expirationDate' => $expiry, 'pin' => $pin, 'pinConfirm' => $confirmation,
        ], allowEmpty: true);
    }
}
