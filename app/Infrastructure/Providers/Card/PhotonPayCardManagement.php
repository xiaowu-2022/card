<?php

namespace App\Infrastructure\Providers\Card;

use App\Domain\CardProvider\DTOs\CardholderUpdateDTO;
use App\Domain\CardProvider\DTOs\ProviderCardFundsDTO;
use App\Domain\CardProvider\DTOs\ProviderCardQuoteDTO;
use App\Domain\CardProvider\DTOs\ProviderCardTransactionDTO;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Support\Logging\PhotonPayLog;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Http;

trait PhotonPayCardManagement
{
    public function quoteCardLoad(string $cardId, string $arrivalAmount, string $requestId): ProviderCardQuoteDTO
    {
        $this->assertLiveReference($cardId);
        $data = $this->managementCall('GET', '/vcc/openApi/v4/preRecharge', array_filter([
            'requestId' => $requestId, 'cardId' => $cardId, 'accountId' => $this->accountId,
            'memberId' => $this->memberId, 'arrivalAmount' => (string) BigDecimal::of($arrivalAmount)->toScale(2),
        ], fn ($value) => $value !== null));
        foreach (['rechargeCurrency', 'arrivalAmountCurrency', 'rechargeFeeCurrency'] as $field) {
            $this->managementRequire(($data[$field] ?? null) === 'USD');
        }
        $this->managementRequire(($data['requestId'] ?? null) === $requestId && ($data['accountId'] ?? null) === $this->accountId);
        $this->managementRequire($this->managementAmount($data['exchangeRate'] ?? null) === '1.00000000');
        $debit = $this->managementAmount($data['rechargeAmount'] ?? null);
        $arrival = $this->managementAmount($data['arrivalAmount'] ?? null);
        $fee = $this->managementAmount($data['rechargeFee'] ?? null);
        $this->managementRequire(BigDecimal::of($arrival)->isEqualTo($arrivalAmount)
            && BigDecimal::of($arrival)->plus($fee)->isEqualTo($debit));

        return new ProviderCardQuoteDTO($requestId, $debit, $arrival, $fee);
    }

    public function confirmCardLoad(string $cardId, string $requestId): ProviderCardFundsDTO
    {
        $this->assertLiveReference($cardId);
        $data = $this->managementCall('POST', '/vcc/openApi/v4/recharge', array_filter([
            'requestId' => $requestId, 'memberId' => $this->memberId,
        ], fn ($value) => $value !== null));
        $this->managementRequire(($data['cardId'] ?? null) === $cardId);
        if (($data['status'] ?? null) === 'failed') {
            return new ProviderCardFundsDTO(ProviderOperationStatus::Failed, $cardId, $requestId);
        }
        $this->managementRequire(($data['status'] ?? null) === 'succeed');
        foreach (['rechargeCurrency', 'arrivalAmountCurrency', 'rechargeFeeCurrency'] as $field) {
            $this->managementRequire(($data[$field] ?? null) === 'USD');
        }
        $this->managementRequire($this->managementAmount($data['exchangeRate'] ?? null) === '1.00000000');

        return $this->fundsResult($cardId, $requestId, $data['transactionId'] ?? null,
            $data['rechargeAmount'] ?? null, $data['arrivalAmount'] ?? null, $data['rechargeFee'] ?? null);
    }

    public function returnCardFunds(string $cardId, string $amount, string $requestId): ProviderCardFundsDTO
    {
        $this->assertLiveReference($cardId);
        $data = $this->managementCall('POST', '/vcc/openApi/v4/rechargeReturn', [
            'requestId' => $requestId, 'cardId' => $cardId, 'returnAmount' => (string) BigDecimal::of($amount)->toScale(2),
        ]);
        $this->managementRequire(($data['cardId'] ?? null) === $cardId
            && (! isset($data['requestId']) || $data['requestId'] === $requestId));
        if (($data['status'] ?? null) === 'failed') {
            return new ProviderCardFundsDTO(ProviderOperationStatus::Failed, $cardId, $requestId);
        }
        $this->managementRequire(($data['status'] ?? null) === 'succeed');

        return $this->fundsResult($cardId, $requestId, $data['transactionId'] ?? null,
            $amount, $data['arrivalAmount'] ?? null, $data['returnFeeAmount'] ?? null);
    }

    public function queryCardFunds(string $cardId, string $requestId, string $kind): ProviderCardFundsDTO
    {
        $this->managementRequire(in_array($kind, ['LOAD', 'RETURN', 'CANCEL_RETURN'], true));
        $type = match ($kind) {
            'LOAD' => 'recharge', 'RETURN' => 'recharge_return', default => 'discard_recharge_return'
        };
        $response = $this->managementTradeQuery($cardId, [
            $kind === 'CANCEL_RETURN' ? 'transactionId' : 'requestId' => $requestId,
            'transactionType' => $type,
        ]);
        $rows = $response['data'];
        if ($rows === []) {
            return new ProviderCardFundsDTO(ProviderOperationStatus::Unknown, $cardId, $requestId);
        }
        $this->managementRequire(count($rows) === 1 && ($response['total'] ?? null) === '1');
        $row = $rows[0];
        $this->managementTradeOwnership($row, $cardId);
        $this->managementRequire(($row['transactionType'] ?? null) === $type
            && ($row[$kind === 'CANCEL_RETURN' ? 'transactionId' : 'requestId'] ?? null) === $requestId);
        if (($row['status'] ?? null) === 'failed') {
            return new ProviderCardFundsDTO(ProviderOperationStatus::Failed, $cardId, $requestId);
        }
        if (($row['status'] ?? null) !== 'succeed') {
            return new ProviderCardFundsDTO(ProviderOperationStatus::Processing, $cardId, $requestId);
        }
        $fundingAccount = $this->matrixAccount ? 'matrix' : 'member';
        $this->managementRequire(($row['txnPrincipalChangeCurrency'] ?? null) === 'USD'
            && ($row['transactionCurrency'] ?? null) === 'USD'
            && ($row['txnPrincipalChangeAccount'] ?? null) === ($kind === 'LOAD' ? $fundingAccount : 'card')
            && ($row['arrivalAccount'] ?? null) === ($kind === 'LOAD' ? 'card' : $fundingAccount));
        $signed = $this->managementAmount($row['txnPrincipalChangeAmount'] ?? null, true);
        $this->managementRequire(BigDecimal::of($signed)->isNegative());
        $debit = (string) BigDecimal::of($signed)->negated();
        $arrival = $this->managementAmount($row['arrivalAmount'] ?? null);
        $fee = (string) BigDecimal::of($debit)->minus($arrival);
        $this->managementRequire(! BigDecimal::of($fee)->isNegative());

        return $this->fundsResult($cardId, $requestId, $row['transactionId'] ?? null, $debit, $arrival, $fee);
    }

    public function getTransaction(string $cardId, string $transactionId): ProviderCardTransactionDTO
    {
        $response = $this->managementTradeQuery($cardId, ['transactionId' => $transactionId]);
        $this->managementRequire(count($response['data']) === 1 && ($response['total'] ?? null) === '1');
        $this->managementTradeOwnership($response['data'][0], $cardId);
        $page = (new PhotonPayTransactionNormalizer)->page($response, $cardId, 1, 20,
            isset($response['data'][0]['cardCurrency']) ? null : $this->getCard($cardId)->assetCode);
        $item = $page->items[0] ?? null;
        $this->managementRequire($item !== null && $item->providerTransactionId === $transactionId);

        return $item;
    }

    public function editCardholderFields(CardholderUpdateDTO $request): void
    {
        $this->assertLiveReference($request->holderId);
        $allowed = ['firstName', 'lastName', 'dateOfBirth', 'email', 'mobile', 'mobilePrefix',
            'nationalityCountryCode', 'certCountryCode', 'residentialAddress', 'residentialCity',
            'residentialState', 'residentialCountryCode', 'residentialPostalCode'];
        $this->managementRequire($request->fields !== [] && array_diff(array_keys($request->fields), $allowed) === []);
        $this->managementCall('POST', '/vcc/openApi/v4/editCardholder', ['cardholderId' => $request->holderId] + $request->fields, allowEmpty: true);
    }

    public function cardholderFieldsMatch(CardholderUpdateDTO $request): bool
    {
        $this->assertLiveReference($request->holderId);
        $rows = $this->managementCall('GET', '/vcc/openApi/v4/pagingVccCardholder', array_filter([
            'cardholderId' => $request->holderId, 'memberId' => $this->memberId, 'matrixAccount' => $this->matrixAccount,
            'pageIndex' => 1, 'pageSize' => 20,
        ], fn ($value) => $value !== null));
        $this->managementRequire(count($rows) === 1 && ($rows[0]['cardholderId'] ?? null) === $request->holderId);
        foreach ($request->fields as $field => $value) {
            if (! is_string($rows[0][$field] ?? null) || ! hash_equals($value, $rows[0][$field])) {
                return false;
            }
        }

        return $request->fields !== [];
    }

    /** @return array<string,mixed> */
    private function managementTradeQuery(string $cardId, array $filters): array
    {
        $this->assertLiveReference($cardId);
        $response = $this->managementCall('GET', '/vcc/openApi/v4/pagingVccTradeOrder', array_filter([
            'cardId' => $cardId, 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card',
            'memberId' => $this->memberId, 'matrixAccount' => $this->matrixAccount,
            'pageIndex' => 1, 'pageSize' => 20,
        ] + $filters, fn ($value) => $value !== null), envelope: true);
        $this->managementRequire(is_array($response['data'] ?? null) && array_is_list($response['data'])
            && ($response['pageIndex'] ?? null) === '1' && ($response['pageSize'] ?? null) === '20');

        return $response;
    }

    private function managementTradeOwnership(array $row, string $cardId): void
    {
        $cardCurrency = $row['cardCurrency'] ?? $this->getCard($cardId)->assetCode;
        $this->managementRequire(($row['cardId'] ?? null) === $cardId && $cardCurrency === 'USD'
            && ($row['cardType'] ?? null) === 'recharge' && ($row['cardFormFactor'] ?? null) === 'virtual_card'
            && ($this->memberId === null || ($row['memberId'] ?? null) === $this->memberId)
            && ($row['matrixAccount'] ?? '') === ($this->matrixAccount ?? ''));
    }

    private function fundsResult(string $cardId, string $requestId, mixed $transactionId, mixed $debit, mixed $arrival, mixed $fee): ProviderCardFundsDTO
    {
        $transactionId = $this->requiredString($transactionId);
        $this->assertLiveReference($transactionId);
        $debit = $this->managementAmount($debit);
        $arrival = $this->managementAmount($arrival);
        $fee = $this->managementAmount($fee);
        $this->managementRequire(BigDecimal::of($debit)->isPositive() && BigDecimal::of($arrival)->plus($fee)->isEqualTo($debit));

        return new ProviderCardFundsDTO(ProviderOperationStatus::Succeeded, $cardId, $requestId, $transactionId, $debit, $arrival, $fee);
    }

    private function managementAmount(mixed $value, bool $signed = false): string
    {
        try {
            if (! is_string($value) || strlen($value) > 40) {
                throw new \UnexpectedValueException;
            }
            $amount = BigDecimal::of($value)->toScale(8, RoundingMode::Unnecessary);
            if ((! $signed && $amount->isNegative()) || strlen(ltrim((string) $amount->getIntegralPart(), '-')) > 12) {
                throw new \UnexpectedValueException;
            }

            return (string) $amount;
        } catch (\Throwable) {
            throw new ProviderUnknownResultException('Card operation result could not be verified.');
        }
    }

    private function managementRequire(bool $condition): void
    {
        if (! $condition) {
            throw new ProviderUnknownResultException('Card operation result could not be verified.');
        }
    }

    /** Sensitive response data is never logged, cached, dispatched or attached to exceptions. */
    private function managementCall(string $method, string $path, #[\SensitiveParameter] array $payload, bool $allowEmpty = false, bool $envelope = false): array
    {
        return PhotonPayLog::run('request', ['method' => $method, 'endpoint' => $path, 'provider_request_ref' => PhotonPayLog::reference(isset($payload['requestId']) && is_string($payload['requestId']) ? $payload['requestId'] : null), 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace) use ($method, $path, $payload, $allowEmpty, $envelope): array {
            $this->assertAvailable();
            try {
                $body = $method === 'POST' ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : null;
                $client = Http::timeout($this->timeoutSeconds)->connectTimeout(5)->withoutRedirecting()->withHeaders($this->authorizationHeaders($body));
                $response = $body === null ? $client->get($this->url($path), $payload)
                    : $client->withBody($body, 'application/json')->post($this->url($path));
                $trace->response($response);
                $json = (new PhotonPayTransactionNormalizer)->decode($response->body());
                // Only documented explicit validation/decline codes. Timeout/system/duplicate codes stay UNKNOWN.
                if ($response->successful() && in_array($json['code'] ?? null, [
                    'VCC1010', 'VCC1011', 'VCC1012', 'VCC1013', 'VCC1014', 'VCC1015', 'VCC1039', 'VCC1050', 'VCC1054', 'VCC1100',
                    'VCC2003', 'VCC2004', 'VCC2006', 'VCC2007', 'VCC2008', 'VCC2009', 'VCC2013', 'VCC2014', 'VCC2016', 'VCC2017', 'VCC2018', 'VCC2021',
                    'VCC3002', 'VCC3005', 'VCC3009', 'VCC3010', 'VCC3011', 'VCC3012', 'VCC3013', 'VCC3014', 'VCC3015', 'VCC3016', 'VCC3021',
                    'VCC3038', 'VCC3041', 'VCC3042', 'VCC3043', 'VCC3044', 'VCC3045',
                ], true)) {
                    throw new ProviderRejectedException('The card provider did not accept this operation.');
                }
                // An arbitrary error code does not prove an external money operation failed.
                // Recovery uses the stable request's exact trade row; never retry this POST.
                $this->managementRequire($response->successful() && ($json['code'] ?? null) === '0000');
                if ($envelope) {
                    return $json;
                }
                if ($allowEmpty && ! isset($json['data'])) {
                    return [];
                }
                $this->managementRequire(is_array($json['data'] ?? null));

                return $json['data'];
            } catch (ProviderRejectedException $exception) {
                throw $exception;
            } catch (\Throwable $failure) {
                $trace->failedBecause($failure);
                throw new ProviderUnknownResultException('Card operation result could not be verified.');
            }
        });
    }
}
