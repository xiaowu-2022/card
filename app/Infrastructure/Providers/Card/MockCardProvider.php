<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\IssueCardRequestDTO;
use App\Domain\CardProvider\DTOs\ProviderBalanceDTO;
use App\Domain\CardProvider\DTOs\ProviderCardDTO;
use App\Domain\CardProvider\DTOs\ProviderOperationDTO;
use App\Domain\CardProvider\DTOs\ProviderSensitiveCardDTO;
use App\Domain\CardProvider\Enums\MockProviderMode;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

final class MockCardProvider implements CardProviderInterface
{
    public function __construct(private MockProviderMode $mode = MockProviderMode::Success) {}

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        return $this->operation('MOCK-CARD-'.Str::upper(Str::random(8)));
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        $this->assertReadable();

        return new ProviderCardDTO(
            $providerCardId,
            'TEST-MOCK-TOKEN',
            'TEST •••• 1234',
            '1234',
            8,
            2029,
            'USD',
            'ACTIVE',
            true,
        );
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
        return $this->operation(null, $providerOperationId);
    }

    private function operation(?string $resourceId, ?string $operationId = null): ProviderOperationDTO
    {
        return match ($this->mode) {
            MockProviderMode::Failed, MockProviderMode::DelayedFailure => throw new ProviderRejectedException('MOCK provider rejected the operation.'),
            MockProviderMode::Timeout => throw new ProviderUnknownResultException('MOCK timeout: the final result is UNKNOWN.'),
            MockProviderMode::RateLimit => throw new ProviderRateLimitException('MOCK provider rate limit.'),
            MockProviderMode::Unknown, MockProviderMode::DuplicateWebhook => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Unknown, $resourceId, 'TEST / MOCK unknown result'),
            MockProviderMode::DelayedSuccess => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Processing, $resourceId, 'TEST / MOCK delayed success'),
            MockProviderMode::Success => new ProviderOperationDTO($operationId ?? 'MOCK-OP-'.Str::upper(Str::random(8)), ProviderOperationStatus::Succeeded, $resourceId, 'TEST / MOCK success'),
        };
    }

    private function assertReadable(): void
    {
        $this->operation(null);
    }
}
