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
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

final class MockCardProvider implements CardProviderInterface
{
    public function __construct(
        private MockProviderMode $mode = MockProviderMode::Success,
        private string $cardholderMode = 'READY',
    ) {}

    public function name(): string
    {
        return 'PHOTONPAY';
    }

    public function available(): bool
    {
        return true;
    }

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        if (in_array($this->cardholderMode, ['UNKNOWN', 'TIMEOUT'], true)) {
            throw new ProviderUnknownResultException('MOCK Cardholder outcome is UNKNOWN.');
        }
        if ($this->cardholderMode === 'REJECTED') {
            throw new ProviderRejectedException('MOCK Cardholder was rejected.');
        }

        return $this->cardholder('MOCK-HOLDER-'.strtoupper(substr(hash('sha256', $request->email ?? $request->mobile ?? $request->firstName), 0, 12)));
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        if ($request->providerCardholderId === null) {
            throw new ProviderRejectedException('MOCK Cardholder identity is missing.');
        }

        return $this->cardholder($request->providerCardholderId);
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        if ($this->cardholderMode === 'TIMEOUT') {
            throw new ProviderUnknownResultException('MOCK Cardholder query is UNKNOWN.');
        }

        return $this->cardholder($providerCardholderId, true);
    }

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool
    {
        return $providerProductReference !== '' && $cardCurrency === 'USD';
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        $result = $this->operation($this->cardId($request->idempotencyKey), $request->idempotencyKey);
        if ($result->status !== ProviderOperationStatus::Succeeded || $result->card === null) {
            return $result;
        }

        $card = new ProviderCardDTO(
            $result->card->providerCardId,
            $result->card->providerCardToken,
            $result->card->maskedPan,
            $result->card->last4,
            $result->card->expiryMonth,
            $result->card->expiryYear,
            $result->card->assetCode,
            $result->card->status,
            $result->card->isTest,
            $request->initialLoadAmount,
        );

        return new ProviderOperationDTO($result->providerOperationId, $result->status, $result->resourceId, $result->message, $card);
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        $this->assertReadable();

        return (new PhotonPayCardResponseNormalizer)->normalize([
            'cardId' => $providerCardId,
            'cardCurrency' => 'USD',
            'cardType' => 'recharge',
            'cardFormFactor' => 'virtual_card',
            'cardNo' => 'TEST-MOCK-FULL-PAN-1234',
            'cvv' => 'TEST-MOCK-CVV',
            'expirationDate' => '08/29',
            'cardStatus' => 'normal',
            'cardBalance' => '20',
        ], true) ?? throw new ProviderUnknownResultException('MOCK Card response could not be normalized.');
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        $this->assertReadable();

        return new ProviderSensitiveCardDTO('TEST-MOCK-NOT-A-PAN', 'MOCK', true);
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        BigDecimal::of($amount)->toScale(8, RoundingMode::Unnecessary);

        return $this->operation($providerCardId);
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operation($providerCardId);
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operation($providerCardId);
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operation($providerCardId);
    }

    public function getBalance(string $providerCardId): ProviderBalanceDTO
    {
        $this->assertReadable();

        return new ProviderBalanceDTO('0.00000000', 'USD');
    }

    public function getTransactions(string $providerCardId): array
    {
        $this->assertReadable();

        return [];
    }

    public function queryOperation(string $providerOperationId): ProviderOperationDTO
    {
        if ($this->mode === MockProviderMode::DelayedSuccess) {
            $card = $this->getCard($this->cardId($providerOperationId));

            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Succeeded, $card->providerCardId, 'TEST / MOCK delayed success', $card);
        }
        if ($this->mode === MockProviderMode::DelayedFailure) {
            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Failed, null, 'TEST / MOCK delayed failure');
        }

        return $this->operation($this->cardId($providerOperationId), $providerOperationId);
    }

    private function operation(?string $resourceId, ?string $operationId = null): ProviderOperationDTO
    {
        $card = $resourceId === null ? null : $this->getCard($resourceId);

        return match ($this->mode) {
            MockProviderMode::Failed => throw new ProviderRejectedException('MOCK provider rejected the operation.'),
            MockProviderMode::Timeout => throw new ProviderUnknownResultException('MOCK timeout: the final result is UNKNOWN.'),
            MockProviderMode::RateLimit => throw new ProviderRateLimitException('MOCK provider rate limit.'),
            MockProviderMode::Unknown, MockProviderMode::DuplicateWebhook => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Unknown, $resourceId, 'TEST / MOCK unknown result'),
            MockProviderMode::DelayedSuccess => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Processing, $resourceId, 'TEST / MOCK delayed success'),
            MockProviderMode::DelayedFailure => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Failed, null, 'TEST / MOCK delayed failure'),
            MockProviderMode::Success => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Succeeded, $resourceId, 'TEST / MOCK success', $card),
        };
    }

    private function cardholder(string $id, bool $synced = false): ProviderCardholderDTO
    {
        $status = match ($this->cardholderMode) {
            'READY' => ProviderCardholderReviewStatus::Ready,
            'PENDING' => $synced ? ProviderCardholderReviewStatus::Pending : ProviderCardholderReviewStatus::Pending,
            'DELAYED_READY' => $synced ? ProviderCardholderReviewStatus::Ready : ProviderCardholderReviewStatus::Pending,
            'ACTION_REQUIRED' => ProviderCardholderReviewStatus::ActionRequired,
            'REJECTED' => ProviderCardholderReviewStatus::Rejected,
            'DISABLED' => ProviderCardholderReviewStatus::Disabled,
            default => ProviderCardholderReviewStatus::Unknown,
        };

        return new ProviderCardholderDTO(
            $id,
            $status,
            $status === ProviderCardholderReviewStatus::Ready ? 'normal' : strtolower($status->value),
            $status === ProviderCardholderReviewStatus::Ready ? 'approved' : strtolower($status->value),
            $status === ProviderCardholderReviewStatus::ActionRequired ? 'Update the requested card setup information.' : null,
        );
    }

    private function cardId(string $idempotencyKey): string
    {
        return 'MOCK-CARD-'.strtoupper(substr(hash('sha256', $idempotencyKey), 0, 12));
    }

    private function assertReadable(): void
    {
        $this->operation(null);
    }
}
