<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderBalanceDTO;
use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use App\Domain\CardProvider\DTOs\ProviderCardholderDTO;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\CardProvider\DTOs\ProviderSensitiveCardDTO;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;

final class UnavailableCardProvider implements CardProviderInterface
{
    public function name(): string
    {
        return 'PHOTONPAY';
    }

    public function available(): bool
    {
        return false;
    }

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        throw $this->exception();
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        throw $this->exception();
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        throw $this->exception();
    }

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool
    {
        return false;
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        throw $this->exception();
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        throw $this->exception();
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        throw $this->exception();
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        throw $this->exception();
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw $this->exception();
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw $this->exception();
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw $this->exception();
    }

    public function getBalance(string $providerCardId): ProviderBalanceDTO
    {
        throw $this->exception();
    }

    public function getTransactions(string $providerCardId): array
    {
        throw $this->exception();
    }

    public function queryOperation(string $providerOperationId): ProviderOperationDTO
    {
        throw $this->exception();
    }

    private function exception(): ProviderUnavailableException
    {
        return new ProviderUnavailableException('Card issuing is unavailable.');
    }
}
