<?php

namespace App\Infrastructure\Providers\Card;

use App\Application\Card\U1Callbacks;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProvider\DTOs\CardholderRequestDTO;
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
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Assets\ExactJson;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Provider truth plus durable wire intents; application actions alone change Wallet/Ledger. */
final class U1CardProvider implements CardProviderInterface
{
    use UnsupportedCardManagement;

    public function __construct(private readonly object $connection) {}

    private function client(): U1ApiClient
    {
        return new U1ApiClient($this->connection->customer_no, Crypt::decryptString($this->connection->api_key_encrypted), $this->connection->base_url);
    }

    public function name(): string
    {
        return 'U1';
    }

    public function available(): bool
    {
        return $this->connection->base_url === U1ApiClient::BASE_URL && $this->connection->check_status === 'VERIFIED';
    }

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        throw new ProviderUnavailableException('U1 does not expose a cardholder API.');
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        throw new ProviderUnavailableException('U1 does not expose a cardholder API.');
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        throw new ProviderUnavailableException('U1 does not expose a cardholder API.');
    }

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool
    {
        return $this->available() && $cardCurrency === 'USD' && $this->group($providerProductReference) !== null;
    }

    private function group(string $id): ?array
    {
        foreach (json_decode($this->connection->group_catalog ?? '[]', true, 128, JSON_THROW_ON_ERROR) as $group) {
            if (($group['groupNo'] ?? null) === $id && ($group['currency'] ?? null) === 'USD') {
                return $group;
            }
        }

        return null;
    }

    private function amount(mixed $value, bool $signed = false): string
    {
        if (! is_string($value) || ! preg_match($signed ? '/^-?\d{1,12}(?:\.\d{1,8})?$/D' : '/^\d{1,12}(?:\.\d{1,8})?$/D', $value)) {
            throw new ProviderUnknownResultException('U1 returned an invalid amount.');
        }

        return (string) BigDecimal::of($value)->toScale(8);
    }

    private function wireAmount(string $value): string
    {
        $value = $this->amount($value);
        if (BigDecimal::of($value)->isLessThanOrEqualTo(0)) {
            throw new ProviderRejectedException('U1 amount must be positive.');
        }
        try {
            return (string) BigDecimal::of($value)->toScale(2);
        } catch (\Throwable) {
            throw new ProviderRejectedException('U1 requests currently support amounts with at most two decimal places.');
        }
    }

    private function prepare(string $kind, string $requestId, string $amount, ?string $cardId, ?string $groupNo, ?string $email = null): array
    {
        if (! Str::isUuid($requestId) || ! $this->available()) {
            throw new ProviderUnavailableException('U1 connection is unavailable.');
        }
        $amount = $this->amount($amount);
        $intentFields = [$kind, $amount, $cardId, $groupNo, 'USD'];
        if ($email !== null && $email !== '') {
            $intentFields[] = $email;
        }
        $hash = hash('sha256', json_encode($intentFields, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($kind, $requestId, $amount, $cardId, $groupNo, $hash): array {
            CardProviderReference::whereKey($this->connection->reference_id)->lockForUpdate()->firstOrFail();
            $connection = DB::table('u1_connections')->where('id', $this->connection->id)->firstOrFail();
            $old = DB::table('u1_operations')->where('connection_id', $connection->id)->where('request_id', $requestId)->first();
            if ($old) {
                if (! hash_equals($old->intent_hash, $hash)) {
                    throw new ProviderUnavailableException('U1 request ID was already used with different details.');
                }

                return [$old, false];
            }
            if ($connection->check_status !== 'VERIFIED' || $connection->api_key_encrypted !== $this->connection->api_key_encrypted) {
                throw new ProviderRejectedException('U1 is not accepting new business.');
            }
            $id = (string) Str::uuid();
            DB::table('u1_operations')->insert(['id' => $id, 'connection_id' => $connection->id, 'request_id' => $requestId,
                'intent_hash' => $hash, 'kind' => $kind, 'card_id' => $cardId, 'group_no' => $groupNo, 'currency' => 'USD',
                'api_key_snapshot_encrypted' => $connection->api_key_encrypted, 'amount' => $amount, 'status' => 'PROCESSING', 'request_ids_confirmed' => true, 'requested_at' => now()]);

            return [DB::table('u1_operations')->where('id', $id)->firstOrFail(), true];
        });
    }

    private function sendOperation(string $kind, string $id, string $amount, ?string $cardId = null, ?string $groupNo = null, ?string $email = null): object
    {
        [$intent, $send] = $this->prepare($kind, $id, $amount, $cardId, $groupNo, $email);
        if (! $send) {
            return $intent;
        } // Ambiguous writes are never blindly resent.
        $values = ['status' => 'UNKNOWN', 'last_checked_at' => now()];
        try {
            $wire = $this->wireAmount($amount);
            $response = match ($kind) {
                'OPEN' => $this->client()->openCard('USD', $wire, $groupNo, requestId: $intent->id, email: $email),
                'LOAD' => $this->client()->recharge('USD', $wire, $cardId, $intent->id),
                'RETURN' => $this->client()->withdraw($wire, $cardId, $intent->id),
                default => throw new ProviderUnavailableException('Unsupported U1 operation.'),
            };
            $apiId = $response['apiId'] ?? null;
            if (! is_string($apiId) || ! preg_match('/^[a-zA-Z0-9._-]{1,120}$/D', $apiId)) {
                throw new ProviderUnknownResultException('U1 returned no usable API ID.');
            }
            $values += ['api_id' => $apiId];
            $values['status'] = 'PROCESSING'; // 0000 is acceptance, never financial completion.
        } catch (ProviderRejectedException) {
            $values['status'] = 'FAILED';
        } catch (\Throwable) {
            $values['status'] = 'UNKNOWN';
        }
        DB::table('u1_operations')->where('id', $intent->id)->update($values);
        if (! empty($values['api_id'])) {
            app(U1Callbacks::class)->dispatchMatching($this->connection->id, $values['api_id']);
        }

        return DB::table('u1_operations')->where('id', $intent->id)->firstOrFail();
    }

    private function operation(string $requestId): object
    {
        return DB::table('u1_operations')->where('connection_id', $this->connection->id)->where('request_id', $requestId)->firstOrFail();
    }

    private function status(object $intent): ProviderOperationStatus
    {
        return match ($intent->status) {
            'SUCCEEDED' => ProviderOperationStatus::Succeeded, 'FAILED' => ProviderOperationStatus::Failed,
            'PROCESSING' => ProviderOperationStatus::Processing, default => ProviderOperationStatus::Unknown,
        };
    }

    private function query(object $intent): object
    {
        if (in_array($intent->status, ['SUCCEEDED', 'FAILED'], true)) {
            return $intent;
        }
        $client = new U1ApiClient($this->connection->customer_no, Crypt::decryptString($this->connection->api_key_encrypted), $this->connection->base_url, v2: (bool) $intent->request_ids_confirmed);
        if (! $intent->api_id) {
            // Legacy requests never sent an upstream ID and cannot be recovered this way.
            if (! $intent->request_ids_confirmed) {
                return $intent;
            }
            $lookup = $client->requestIds($intent->id);
            $ids = $lookup['data'] ?? null;
            // More than one candidate is ambiguous: never choose one or resend the write.
            if (($lookup['resultCode'] ?? null) !== '0000' || ! is_string($ids) || ! preg_match('/^[a-zA-Z0-9._-]{1,120}$/D', $ids)) {
                throw new ProviderUnknownResultException('U1 request could not be uniquely located.');
            }
            $intent = DB::transaction(function () use ($intent, $ids): object {
                $current = DB::table('u1_operations')->where('id', $intent->id)->lockForUpdate()->firstOrFail();
                if ($current->api_id && $current->api_id !== $ids) {
                    throw new ProviderUnknownResultException('U1 request identity does not match.');
                }
                if (! $current->api_id) {
                    DB::table('u1_operations')->where('id', $intent->id)->update(['api_id' => $ids]);
                }

                return DB::table('u1_operations')->where('id', $intent->id)->firstOrFail();
            });
        }
        $response = $client->operation($intent->api_id);
        $body = $response['data'] ?? null;
        if (is_array($body) && ! array_is_list($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
        }
        if (! is_string($body)) {
            throw new ProviderUnknownResultException('U1 operation evidence is unavailable.');
        }
        $facts = (new U1NotificationVerifier)->verify($body, Crypt::decryptString($this->connection->api_key_encrypted));
        if (! $facts['verified'] && $intent->api_key_snapshot_encrypted) {
            $facts = (new U1NotificationVerifier)->verify($body, Crypt::decryptString($intent->api_key_snapshot_encrypted));
        }
        $event = ExactJson::decode($body);
        if (! $facts['verified'] || ($event['apiId'] ?? null) !== $intent->api_id) {
            throw new ProviderUnknownResultException('U1 operation evidence does not match.');
        }
        $prefix = match ($intent->kind) {
            'OPEN' => 'openvcc', 'LOAD' => 'rechargevcc', 'RETURN' => 'withdrawvcc', default => ''
        };
        if (! in_array($event['type'] ?? null, [$prefix.'.success', $prefix.'.failed', $prefix.'.fail'], true)) {
            throw new ProviderUnknownResultException('U1 operation evidence has an unexpected type.');
        }
        $success = $event['type'] === $prefix.'.success';
        $values = ['status' => $success ? 'SUCCEEDED' : 'FAILED', 'last_checked_at' => now()];
        if ($success && $intent->kind === 'OPEN') {
            if (! is_string($event['cardNo'] ?? null) || $event['cardNo'] === '' || strlen($event['cardNo']) > 180) {
                throw new ProviderUnknownResultException('U1 card identity is unavailable.');
            }
            $values['card_id'] = $event['cardNo'];
        }
        DB::transaction(function () use ($intent, $values): void {
            $current = DB::table('u1_operations')->where('id', $intent->id)->lockForUpdate()->firstOrFail();
            if (! in_array($current->status, ['SUCCEEDED', 'FAILED'], true)) {
                DB::table('u1_operations')->where('id', $intent->id)->update($values);
            }
        });

        return DB::table('u1_operations')->where('id', $intent->id)->firstOrFail();
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        if ($request->formFactor !== 'virtual_card' || ! $this->productAvailable($request->providerProductReference, $request->cardCurrency)) {
            throw new ProviderUnavailableException('This U1 card group is unavailable.');
        }
        $this->wireAmount($request->initialLoadAmount);
        $intent = $this->sendOperation('OPEN', $request->idempotencyKey, $request->initialLoadAmount, groupNo: $request->providerProductReference, email: $request->verificationEmail);

        return new ProviderOperationDTO($request->idempotencyKey, $this->status($intent));
    }

    public function queryOperation(string $providerOperationId): ProviderOperationDTO
    {
        $intent = $this->query($this->operation($providerOperationId));
        $card = $intent->kind === 'OPEN' && $intent->status === 'SUCCEEDED' ? $this->getCard($intent->card_id) : null;

        return new ProviderOperationDTO($providerOperationId, $this->status($intent), $intent->card_id, card: $card);
    }

    private function cardData(string $cardId): array
    {
        $response = $this->client()->card($cardId);
        $data = $response['data'] ?? null;
        if ($response['resultCode'] !== '0000' || ! is_array($data) || ($data['cardNo'] ?? null) !== $cardId || ! is_string($data['status'] ?? null)) {
            throw new ProviderUnknownResultException('U1 card information does not match.');
        }
        $data['amount'] = $this->amount($data['amount'] ?? null);

        return $data;
    }

    public function getBalance(string $providerCardId): ProviderBalanceDTO
    {
        return new ProviderBalanceDTO($this->cardData($providerCardId)['amount'], 'USD');
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        $data = $this->cardData($providerCardId);
        $saved = UserCard::where('provider', 'U1')->where('provider_card_id', $providerCardId)
            ->whereIn('card_product_id', DB::table('card_products')->where('card_provider_reference_id', $this->connection->reference_id)->select('id'))->first();
        if ($saved) {
            $last4 = $saved->last4;
            $expiry = $saved->expiry;
        } else {
            $sensitive = $this->revealCard($providerCardId);
            $last4 = substr($sensitive->displayPan, -4);
            $expiry = $sensitive->expiry;
        }
        $status = match ($data['status']) {
            '1' => 'normal', '2', '-1' => 'inactive', '3', '4' => 'cancelled', '8' => 'frozen', '5', '6', '7' => 'disabled', default => throw new ProviderUnknownResultException('Unknown U1 card status.')
        };

        return new ProviderCardDTO($providerCardId, $providerCardId, '•••• '.$last4, $last4,
            $expiry ? (int) substr($expiry, 0, 2) : null, $expiry ? 2000 + (int) substr($expiry, 3, 2) : null, 'USD', $status, false, $data['amount']);
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        $response = $this->client()->sensitiveCard($providerCardId);
        $data = $response['data'] ?? [];
        if ($response['resultCode'] !== '0000' || ($data['status'] ?? null) !== '1'
            || ! is_string($data['cardNo'] ?? null) || ! preg_match('/^\d{12,19}$/D', $data['cardNo'])
            || ! is_string($data['cvv'] ?? null) || ! preg_match('/^\d{3,4}$/D', $data['cvv'])
            || ! is_string($data['expiry'] ?? null) || ! preg_match('/^(0[1-9]|1[0-2])\/\d{2}$/D', $data['expiry'])) {
            throw new ProviderUnknownResultException('U1 sensitive card information is unavailable.');
        }

        return new ProviderSensitiveCardDTO($data['cardNo'], $data['cvv'], false, $data['expiry']);
    }

    public function quoteCardLoad(string $cardId, string $arrivalAmount, string $requestId): ProviderCardQuoteDTO
    {
        $this->wireAmount($arrivalAmount);
        if (! $this->available()) {
            throw new ProviderRejectedException('U1 is not accepting new business.');
        }

        return new ProviderCardQuoteDTO($requestId, $this->amount($arrivalAmount), $this->amount($arrivalAmount), '0.00000000');
    }

    public function confirmCardLoad(string $cardId, string $requestId): ProviderCardFundsDTO
    {
        $order = DB::table('card_management_orders')->where('provider_request_id', $requestId)->where('provider_card_id', $cardId)->where('kind', 'LOAD')->firstOrFail();
        $intent = $this->sendOperation('LOAD', $requestId, $order->amount, $cardId);

        return $this->funds($intent);
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        if ($assetCode !== 'USD') {
            throw new ProviderUnavailableException('U1 currency is unavailable.');
        }
        $this->wireAmount($amount);
        $intent = $this->sendOperation('LOAD', $idempotencyKey, $amount, $providerCardId);

        return new ProviderOperationDTO($idempotencyKey, $this->status($intent), $providerCardId);
    }

    public function returnCardFunds(string $cardId, string $amount, string $requestId): ProviderCardFundsDTO
    {
        $card = UserCard::where('provider', 'U1')->where('provider_card_id', $cardId)
            ->whereIn('card_product_id', DB::table('card_products')->where('card_provider_reference_id', $this->connection->reference_id)->select('id'))->firstOrFail();
        if (($this->group($card->product->provider_product_ref)['model'] ?? null) !== 'SHARE') {
            throw new ProviderRejectedException('This U1 card group does not support withdrawals.');
        }
        $this->wireAmount($amount);

        return $this->funds($this->sendOperation('RETURN', $requestId, $amount, $cardId));
    }

    private function funds(object $intent): ProviderCardFundsDTO
    {
        $success = $intent->status === 'SUCCEEDED';

        return new ProviderCardFundsDTO($this->status($intent), $intent->card_id, $intent->request_id,
            $success ? $intent->api_id : null, $success ? $intent->amount : null, $success ? $intent->amount : null, $success ? '0.00000000' : null);
    }

    public function queryCardFunds(string $cardId, string $requestId, string $kind): ProviderCardFundsDTO
    {
        $intent = $this->operation($requestId);
        if ($intent->card_id !== $cardId || $intent->kind !== ($kind === 'LOAD' ? 'LOAD' : 'RETURN')) {
            throw new ProviderUnknownResultException('U1 funding intent does not match.');
        }

        return $this->funds($this->query($intent));
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderRejectedException('U1 does not document a freeze interface.');
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderRejectedException('U1 does not document an unfreeze interface.');
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderRejectedException('U1 does not document a cancellation interface.');
    }

    public function getTransactionPage(string $providerCardId, int $page, int $pageSize): ProviderTransactionPageDTO
    {
        if ($page < 1 || $page > 100000) {
            throw new ProviderUnavailableException('Invalid transaction page.');
        }
        $response = $this->client()->transactions($providerCardId, ($page - 1) * $pageSize, $pageSize);
        $rows = $response['vcctradelist'] ?? null;
        if ($response['resultCode'] !== '0000' || ! is_array($rows) || count($rows) > $pageSize) {
            throw new ProviderUnknownResultException('U1 transaction evidence is unavailable.');
        }
        $items = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ($row['cardNo'] ?? null) !== $providerCardId || ! is_string($row['uuid'] ?? null) || $row['uuid'] === ''
                || ! is_string($row['tradeCurrency'] ?? null) || ! preg_match('/^[A-Z]{3}$/D', $row['tradeCurrency'])
                || ! is_string($row['tradeType'] ?? null) || ! is_string($row['tradeTime'] ?? null) || ! preg_match('/^\d{10,16}$/D', $row['tradeTime'])
                || ! in_array($row['tradeStatus'] ?? null, ['1', '2'], true)) {
                throw new ProviderUnknownResultException('U1 transaction fields are invalid.');
            }
            $time = strlen($row['tradeTime']) > 10 ? CarbonImmutable::createFromTimestampMs($row['tradeTime'], 'UTC') : CarbonImmutable::createFromTimestamp($row['tradeTime'], 'UTC');
            $items[] = new ProviderCardTransactionDTO($row['uuid'], $this->amount($row['tradeAmount'] ?? null, true), $row['tradeCurrency'],
                $row['tradeType'], $row['tradeStatus'] === '1' ? 'SUCCEEDED' : 'FAILED', $time->format('Y-m-d H:i:s'), null,
                isset($row['feeAmount']) ? $this->amount($row['feeAmount'], true) : null, $row['feeCurrency'] ?? null);
        }

        return new ProviderTransactionPageDTO($items, $page, count($items) === $pageSize);
    }

    public function getTransactions(string $providerCardId): array
    {
        return array_map(fn ($row) => new ProviderTransactionDTO($row->providerTransactionId, $row->amount, $row->currency, $row->type, $row->state, new \DateTimeImmutable($row->occurredAt)), $this->getTransactionPage($providerCardId, 1, 100)->items);
    }

    public function getTransaction(string $cardId, string $transactionId): ProviderCardTransactionDTO
    {
        // The documented API has no single-trade lookup. Search bounded pages without inventing evidence.
        for ($page = 1; $page <= 20; $page++) {
            $result = $this->getTransactionPage($cardId, $page, 100);
            foreach ($result->items as $item) {
                if ($item->providerTransactionId === $transactionId) {
                    return $item;
                }
            }
            if (! $result->hasMore) {
                break;
            }
        }
        throw new ProviderUnknownResultException('U1 transaction was not found in the supported lookup window.');
    }
}
