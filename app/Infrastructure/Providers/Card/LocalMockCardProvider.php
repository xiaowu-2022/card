<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\Contracts\CardProviderInterface;
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
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Str;

/** Offline provider simulator. No business tables, Ledger writes, HTTP or real identifiers. */
final class LocalMockCardProvider implements CardProviderInterface
{
    public function __construct(private LocalCardSimulatorStore $store = new LocalCardSimulatorStore) {}

    public function name(): string
    {
        return 'PHOTONPAY';
    }

    public function available(): bool
    {
        return LocalCardSimulation::enabled();
    }

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool
    {
        return $this->available() && $cardCurrency === 'USD' && preg_match('/^(MOCK|TEST|DEMO)[-_]/i', $providerProductReference) === 1;
    }

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        $this->holderFault();
        $id = 'MOCK-LOCAL-HOLDER-'.Str::uuid();
        // Never retain identity document bytes, numbers, signed URLs or full material DTOs.
        $this->store->access(function (array &$state) use ($id): void {
            $state['holders'][$id] = [];
        });

        return $this->getCardholder($id);
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        $this->editCardholderFields(new CardholderUpdateDTO($request->providerCardholderId ?? '', array_filter([
            'email' => $request->email, 'mobile' => $request->mobile, 'mobilePrefix' => $request->mobilePrefix,
            'dateOfBirth' => $request->dateOfBirth, 'residentialAddress' => $request->residentialAddress,
        ], fn ($value) => $value !== null)));

        return $this->getCardholder($request->providerCardholderId ?? '');
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        $this->holderFault();
        $this->store->access(fn (array &$state) => $this->holder($state, $providerCardholderId), false);

        return new ProviderCardholderDTO($providerCardholderId, ProviderCardholderReviewStatus::Ready, 'normal', 'approved');
    }

    public function editCardholderFields(CardholderUpdateDTO $request): void
    {
        $this->holderFault();
        $allowed = ['email', 'mobile', 'mobilePrefix', 'dateOfBirth', 'nationalityCountryCode', 'certCountryCode',
            'residentialCountryCode', 'residentialState', 'residentialCity', 'residentialAddress', 'residentialPostalCode'];
        if (array_diff(array_keys($request->fields), $allowed)) {
            throw new ProviderRejectedException('Unsupported simulated holder fields.');
        }
        $this->store->access(function (array &$state) use ($request): void {
            $state['holders'][$request->holderId] = array_replace($this->holder($state, $request->holderId), $request->fields);
        });
    }

    public function cardholderFieldsMatch(CardholderUpdateDTO $request): bool
    {
        return $this->store->access(function (array &$state) use ($request): bool {
            $holder = $this->holder($state, $request->holderId);
            foreach ($request->fields as $key => $value) {
                if (($holder[$key] ?? null) !== $value) {
                    return false;
                }
            }

            return true;
        }, false);
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        if (! $this->productAvailable($request->providerProductReference, $request->cardCurrency)) {
            throw new ProviderRejectedException('Only simulated USD products are available.');
        }
        $this->store->access(fn (array &$state) => $this->holder($state, $request->holderReference), false);
        $id = 'MOCK-LOCAL-CARD-'.substr(hash('sha256', $request->idempotencyKey), 0, 24);
        $op = $this->operation($id, $request->idempotencyKey, 'ISSUE', $this->amount($request->initialLoadAmount), $request->holderReference);

        return $this->operationDTO($op);
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        $pending = $this->store->access(fn (array &$state) => array_keys(array_filter($state['operations'],
            fn (array $op) => $op['card'] === $providerCardId && in_array($op['kind'], ['FREEZE', 'UNFREEZE', 'CANCEL'], true)
                && ! in_array($op['status'], ['SUCCEEDED', 'FAILED'], true))), false);
        foreach ($pending as $request) {
            $this->resolve($request);
        }
        $row = $this->store->access(fn (array &$state) => $this->card($state, $providerCardId), false);

        return new ProviderCardDTO($providerCardId, '', 'TEST •••• 1234', '1234', 8, 2099, 'USD', $row['status'], true, $row['balance']);
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        $this->getCard($providerCardId);

        return new ProviderSensitiveCardDTO('TEST-MOCK-NOT-A-PAN-1234', '000', true, '08/99');
    }

    public function getBalance(string $providerCardId): ProviderBalanceDTO
    {
        return new ProviderBalanceDTO($this->getCard($providerCardId)->providerBalance, 'USD');
    }

    public function quoteCardLoad(string $cardId, string $arrivalAmount, string $requestId): ProviderCardQuoteDTO
    {
        $amount = $this->amount($arrivalAmount);
        $this->store->access(function (array &$state) use ($cardId, $amount, $requestId): void {
            $this->card($state, $cardId);
            $quote = ['card' => $cardId, 'amount' => $amount];
            if (isset($state['quotes'][$requestId]) && $state['quotes'][$requestId] !== $quote) {
                throw new ProviderRejectedException('Simulated request identity was reused.');
            }
            $state['quotes'][$requestId] = $quote;
        });

        return new ProviderCardQuoteDTO($requestId, $amount, $amount, '0.00000000');
    }

    public function confirmCardLoad(string $cardId, string $requestId): ProviderCardFundsDTO
    {
        $quote = $this->store->access(fn (array &$state) => $state['quotes'][$requestId] ?? null, false);
        if (! $quote || $quote['card'] !== $cardId) {
            throw new ProviderRejectedException('Simulated quote is missing.');
        }

        return $this->fundsDTO($this->operation($cardId, $requestId, 'LOAD', $quote['amount']));
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        if ($assetCode !== 'USD') {
            throw new ProviderRejectedException('Unsupported simulated asset.');
        }
        $this->quoteCardLoad($providerCardId, $amount, $idempotencyKey);
        $this->confirmCardLoad($providerCardId, $idempotencyKey);

        return $this->queryOperation($idempotencyKey);
    }

    public function returnCardFunds(string $cardId, string $amount, string $requestId): ProviderCardFundsDTO
    {
        return $this->fundsDTO($this->operation($cardId, $requestId, 'RETURN', $this->amount($amount)));
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operationDTO($this->operation($providerCardId, $idempotencyKey, 'FREEZE'));
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operationDTO($this->operation($providerCardId, $idempotencyKey, 'UNFREEZE'));
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        return $this->operationDTO($this->operation($providerCardId, $idempotencyKey, 'CANCEL'));
    }

    public function queryOperation(string $providerOperationId): ProviderOperationDTO
    {
        return $this->operationDTO($this->resolve($providerOperationId));
    }

    public function queryCardFunds(string $cardId, string $requestId, string $kind): ProviderCardFundsDTO
    {
        // Verify ownership BEFORE advancing the simulated outcome.
        $op = $this->store->access(fn (array &$state) => $state['operations'][$requestId] ?? null, false);
        if (! $op || $op['card'] !== $cardId || $op['kind'] !== $kind || ! in_array($kind, ['LOAD', 'RETURN', 'CANCEL_RETURN'], true)) {
            throw new ProviderUnknownResultException('Simulated funds evidence is unavailable.');
        }

        return $this->fundsDTO($this->resolve($requestId));
    }

    public function getTransactionPage(string $providerCardId, int $page, int $pageSize): ProviderTransactionPageDTO
    {
        if ($page < 1 || $pageSize < 1 || $pageSize > 100) {
            throw new ProviderRejectedException('Invalid simulated page.');
        }
        $rows = $this->store->access(function (array &$state) use ($providerCardId): array {
            $this->card($state, $providerCardId);

            return array_reverse(array_values($state['trades'][$providerCardId] ?? []));
        }, false);

        return new ProviderTransactionPageDTO(array_map($this->tradeDTO(...), array_slice($rows, ($page - 1) * $pageSize, $pageSize)), $page, count($rows) > $page * $pageSize);
    }

    public function getTransactions(string $providerCardId): array
    {
        $rows = $this->store->access(function (array &$state) use ($providerCardId): array {
            $this->card($state, $providerCardId);

            return array_values($state['trades'][$providerCardId] ?? []);
        }, false);

        return array_map(fn (array $row) => new ProviderTransactionDTO($row['id'], $row['amount'], 'USD', $row['type'], 'succeed', new \DateTimeImmutable($row['at'])), $rows);
    }

    public function getTransaction(string $cardId, string $transactionId): ProviderCardTransactionDTO
    {
        $row = $this->store->access(function (array &$state) use ($cardId, $transactionId): array {
            $this->card($state, $cardId);

            return $state['trades'][$cardId][$transactionId] ?? throw new ProviderUnknownResultException('Simulated transaction is missing.');
        }, false);

        return $this->tradeDTO($row);
    }

    /** Test-provider event only; applications still verify a notification then query this provider truth. */
    public function simulatePurchase(string $cardId, string $requestId, string $amount): string
    {
        $op = $this->operation($cardId, $requestId, 'PURCHASE', $this->amount($amount));

        return 'MOCK-LOCAL-TX-'.hash('sha256', $op['request']);
    }

    private function operation(string $cardId, string $requestId, string $kind, string $amount = '0.00000000', ?string $holder = null): array
    {
        $op = $this->store->access(function (array &$state) use ($cardId, $requestId, $kind, $amount, $holder): array {
            $intent = compact('cardId', 'kind', 'amount', 'holder');
            if (isset($state['operations'][$requestId])) {
                $old = $state['operations'][$requestId];
                if ($old['intent'] !== $intent) {
                    throw new ProviderRejectedException('Simulated request identity was reused.');
                }

                return $old;
            }
            $mode = (string) config('card-provider.mock_mode', 'SUCCESS');
            $op = ['card' => $cardId, 'request' => $requestId, 'kind' => $kind, 'amount' => $amount,
                'holder' => $holder, 'intent' => $intent, 'mode' => $mode, 'status' => 'PROCESSING'];
            if ($mode === 'SUCCESS') {
                $this->apply($state, $op);
            } elseif ($mode === 'FAILED') {
                $op['status'] = 'FAILED';
            } elseif (! in_array($mode, ['DELAYED_SUCCESS', 'DELAYED_FAILURE'], true)) {
                $op['status'] = 'UNKNOWN';
            }
            $state['operations'][$requestId] = $op;

            return $op;
        });
        // Throw after the simulated provider has durably recorded the uncertain request.
        if ($op['mode'] === 'TIMEOUT' && $op['status'] === 'UNKNOWN') {
            throw new ProviderUnknownResultException('Simulated timeout: UNKNOWN.');
        }
        if ($op['mode'] === 'RATE_LIMIT' && $op['status'] === 'UNKNOWN') {
            throw new ProviderRateLimitException('Simulated rate limit.');
        }
        if ($op['status'] === 'FAILED') {
            throw new ProviderRejectedException('Simulated provider rejected the operation.');
        }

        return $op;
    }

    private function resolve(string $request): array
    {
        return $this->store->access(function (array &$state) use ($request): array {
            $op = $state['operations'][$request] ?? throw new ProviderUnknownResultException('Simulated operation is missing.');
            if (in_array($op['status'], ['SUCCEEDED', 'FAILED'], true)) {
                return $op;
            }
            if ($op['mode'] === 'DELAYED_FAILURE') {
                $op['status'] = 'FAILED';
            } elseif ($op['mode'] === 'DELAYED_SUCCESS' || config('card-provider.mock_mode') === 'SUCCESS') {
                $this->apply($state, $op);
            }
            $state['operations'][$request] = $op;

            return $op;
        });
    }

    private function apply(array &$state, array &$op): void
    {
        $id = $op['card'];
        if ($op['kind'] === 'ISSUE') {
            $state['cards'][$id] = ['balance' => $op['amount'], 'status' => 'normal', 'holder' => $op['holder']];
        } else {
            $card = $this->card($state, $id);
            if ($card['status'] === 'cancelled' || ($op['kind'] === 'LOAD' && $card['status'] !== 'normal')
                || ($op['kind'] === 'PURCHASE' && $card['status'] !== 'normal')) {
                $op['status'] = 'FAILED';

                return;
            }
            if (in_array($op['kind'], ['LOAD', 'RETURN', 'PURCHASE'], true)) {
                $value = BigDecimal::of($card['balance']);
                $balance = $op['kind'] === 'LOAD' ? $value->plus($op['amount']) : $value->minus($op['amount']);
                if ($balance->isNegative() || $balance->isGreaterThan('999999999999.99999999')) {
                    $op['status'] = 'FAILED';

                    return;
                }
                $card['balance'] = (string) $balance->toScale(8);
            } else {
                // Cancellation return is separate provider evidence, never direct Wallet credit.
                if ($op['kind'] === 'CANCEL' && ! BigDecimal::of($card['balance'])->isZero()) {
                    $tx = 'MOCK-LOCAL-TX-'.hash('sha256', 'cancel-return:'.$op['request']);
                    $state['operations'][$tx] = ['card' => $id, 'request' => $tx, 'kind' => 'CANCEL_RETURN',
                        'amount' => $card['balance'], 'mode' => 'SUCCESS', 'status' => 'SUCCEEDED', 'transaction' => $tx];
                    $state['trades'][$id][$tx] = ['id' => $tx, 'amount' => $card['balance'], 'type' => 'discard_recharge_return', 'at' => now()->format('Y-m-d\TH:i:s')];
                    $card['balance'] = '0.00000000';
                }
                $card['status'] = match ($op['kind']) {
                    'FREEZE' => 'frozen', 'UNFREEZE' => 'normal', 'CANCEL' => 'cancelled'
                };
            }
            $state['cards'][$id] = $card;
        }
        $op['status'] = 'SUCCEEDED';
        if (in_array($op['kind'], ['LOAD', 'RETURN', 'PURCHASE'], true)) {
            $tx = 'MOCK-LOCAL-TX-'.hash('sha256', $op['request']);
            $state['trades'][$id][$tx] = ['id' => $tx, 'amount' => $op['amount'],
                'type' => match ($op['kind']) {
                    'LOAD' => 'recharge', 'RETURN' => 'recharge_return', 'PURCHASE' => 'purchase'
                },
                'at' => now()->format('Y-m-d\TH:i:s')];
        }
    }

    private function operationDTO(array $op): ProviderOperationDTO
    {
        $card = $op['status'] === 'SUCCEEDED' ? $this->getCard($op['card']) : null;
        // Issuance evidence retains the original arrival amount even after later card spending.
        if ($card && $op['kind'] === 'ISSUE') {
            $card = new ProviderCardDTO($card->providerCardId, '', $card->maskedPan, $card->last4,
                $card->expiryMonth, $card->expiryYear, 'USD', 'normal', true, $op['amount']);
        }

        return new ProviderOperationDTO($op['request'], ProviderOperationStatus::from($op['status']), $op['card'], 'TEST / LOCAL MOCK',
            $card);
    }

    private function fundsDTO(array $op): ProviderCardFundsDTO
    {
        return new ProviderCardFundsDTO(ProviderOperationStatus::from($op['status']), $op['card'], $op['request'],
            $op['status'] === 'SUCCEEDED' ? ($op['transaction'] ?? 'MOCK-LOCAL-TX-'.hash('sha256', $op['request'])) : null,
            $op['amount'], $op['amount'], '0.00000000');
    }

    private function tradeDTO(array $row): ProviderCardTransactionDTO
    {
        return new ProviderCardTransactionDTO($row['id'], $row['amount'], 'USD', match ($row['type']) {
            'recharge' => 'transfer_in', 'recharge_return', 'discard_recharge_return' => 'transfer_out', default => 'purchase',
        }, 'completed', $row['at'], 'TEST / LOCAL MOCK');
    }

    private function card(array $state, string $id): array
    {
        return $state['cards'][$id] ?? throw new ProviderRejectedException('Unknown simulated card; real and historical identities are not imported.');
    }

    private function holder(array $state, string $id): array
    {
        return $state['holders'][$id] ?? throw new ProviderRejectedException('Unknown simulated cardholder.');
    }

    private function amount(string $value): string
    {
        try {
            $amount = BigDecimal::of($value)->toScale(8, RoundingMode::Unnecessary);
        } catch (\Throwable) {
            throw new ProviderRejectedException('Invalid simulated amount.');
        }
        if (! $amount->isPositive() || $amount->isGreaterThan('999999999999.99999999')) {
            throw new ProviderRejectedException('Invalid simulated amount.');
        }

        return (string) $amount;
    }

    private function holderFault(): void
    {
        $mode = config('card-provider.mock_cardholder_mode', 'READY');
        if ($mode === 'REJECTED') {
            throw new ProviderRejectedException('Simulated cardholder rejected.');
        }
        if ($mode !== 'READY') {
            throw new ProviderUnknownResultException('Simulated cardholder result is UNKNOWN.');
        }
    }
}
