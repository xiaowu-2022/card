<?php

namespace App\Domain\CardProvider\Contracts;

use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderBalanceDTO;
use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use App\Domain\CardProvider\DTOs\ProviderCardFundsDTO;
use App\Domain\CardProvider\DTOs\ProviderCardholderDTO;
use App\Domain\CardProvider\DTOs\ProviderCardQuoteDTO;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\CardProvider\DTOs\ProviderSensitiveCardDTO;
use App\Domain\CardProvider\DTOs\ProviderTransactionDTO;
use App\Domain\CardProvider\DTOs\ProviderTransactionPageDTO;

interface CardProviderInterface
{
    public function name(): string;

    public function available(): bool;

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO;

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO;

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO;

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool;

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO;

    public function getCard(string $providerCardId): ProviderCardDTO;

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO;

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO;

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO;

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO;

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO;

    public function getBalance(string $providerCardId): ProviderBalanceDTO;

    /** @return list<ProviderTransactionDTO> */
    public function getTransactions(string $providerCardId): array;

    public function getTransactionPage(string $providerCardId, int $page, int $pageSize): ProviderTransactionPageDTO;

    public function queryOperation(string $providerOperationId): ProviderOperationDTO;

    public function quoteCardLoad(string $cardId, string $arrivalAmount, string $requestId): ProviderCardQuoteDTO;

    public function confirmCardLoad(string $cardId, string $requestId): ProviderCardFundsDTO;

    public function returnCardFunds(string $cardId, string $amount, string $requestId): ProviderCardFundsDTO;

    public function queryCardFunds(string $cardId, string $requestId, string $kind): ProviderCardFundsDTO;

    public function getTransaction(string $cardId, string $transactionId): ProviderCardTransactionDTO;

    public function editCardholderFields(CardholderUpdateDTO $request): void;

    public function cardholderFieldsMatch(CardholderUpdateDTO $request): bool;
}
