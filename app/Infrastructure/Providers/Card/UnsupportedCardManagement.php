<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\DTOs\ProviderCardFundsDTO;
use App\Domain\CardProvider\DTOs\ProviderCardQuoteDTO;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;

trait UnsupportedCardManagement
{
    public function cardholderFieldsMatch(CardholderUpdateDTO $request): bool
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function quoteCardLoad(string $cardId, string $arrivalAmount, string $requestId): ProviderCardQuoteDTO
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function confirmCardLoad(string $cardId, string $requestId): ProviderCardFundsDTO
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function returnCardFunds(string $cardId, string $amount, string $requestId): ProviderCardFundsDTO
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function queryCardFunds(string $cardId, string $requestId, string $kind): ProviderCardFundsDTO
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function getTransaction(string $cardId, string $transactionId): ProviderCardTransactionDTO
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }

    public function editCardholderFields(CardholderUpdateDTO $request): void
    {
        throw new ProviderUnavailableException('Card management is unavailable.');
    }
}
