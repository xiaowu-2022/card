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
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class PhotonPayCardProvider implements CardProviderInterface
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $appId,
        private readonly string $appSecret,
        private readonly string $privateKey,
        private readonly string $accountId,
        private readonly ?string $memberId,
        private readonly ?string $matrixAccount,
        private readonly int $timeoutSeconds,
        private readonly PhotonPayCardResponseNormalizer $cards,
    ) {}

    public function name(): string
    {
        return 'PHOTONPAY';
    }

    public function available(): bool
    {
        return str_starts_with($this->baseUrl, 'https://')
            && $this->appId !== '' && $this->appSecret !== '' && $this->privateKey !== '' && $this->accountId !== ''
            && openssl_pkey_get_private($this->normalizedPrivateKey()) !== false;
    }

    public function createCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        $front = $this->uploadDocument($request->identityDocument->frontContents, $request->identityDocument->frontMimeType, 'front');
        $back = $request->identityDocument->backContents === null ? null : $this->uploadDocument(
            $request->identityDocument->backContents,
            $request->identityDocument->backMimeType ?? 'image/jpeg',
            'back',
        );
        $data = $this->post('/vcc/openApi/v4/addCardholder', $this->cardholderPayload($request, $front, $back));
        $id = $this->requiredString($data['cardholderId'] ?? null);

        return new ProviderCardholderDTO($id, ProviderCardholderReviewStatus::Pending, 'pending', 'pending');
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        if ($request->providerCardholderId === null) {
            throw new ProviderRejectedException('Provider Cardholder identity is missing.');
        }
        $front = $this->uploadDocument($request->identityDocument->frontContents, $request->identityDocument->frontMimeType, 'front');
        $back = $request->identityDocument->backContents === null ? null : $this->uploadDocument(
            $request->identityDocument->backContents,
            $request->identityDocument->backMimeType ?? 'image/jpeg',
            'back',
        );
        $payload = ['cardholderId' => $request->providerCardholderId] + $this->cardholderPayload($request, $front, $back);
        $data = $this->post('/vcc/openApi/v4/editCardholder', $payload);

        return new ProviderCardholderDTO(
            $this->requiredString($data['cardholderId'] ?? $request->providerCardholderId),
            ProviderCardholderReviewStatus::Pending,
            'pending',
            'pending',
        );
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        $data = $this->get('/vcc/openApi/v4/pagingVccCardholder', array_filter([
            'pageIndex' => 1,
            'pageSize' => 20,
            'memberId' => $this->memberId,
            'matrixAccount' => $this->matrixAccount,
            'cardholderId' => $providerCardholderId,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
        $rows = is_array($data) ? $data : [];
        $row = collect($rows)->first(fn (mixed $candidate): bool => is_array($candidate)
            && hash_equals($providerCardholderId, (string) ($candidate['cardholderId'] ?? '')));
        if (! is_array($row)) {
            throw new ProviderUnknownResultException('Provider Cardholder status is not yet available.');
        }
        $providerStatus = strtolower((string) ($row['status'] ?? ''));
        $reviewStatus = strtolower((string) ($row['cardholderReviewStatus'] ?? ''));
        $status = match (true) {
            $providerStatus === 'disabled' => ProviderCardholderReviewStatus::Disabled,
            $reviewStatus === 'approved' && $providerStatus === 'normal' => ProviderCardholderReviewStatus::Ready,
            $reviewStatus === 'modify' || $providerStatus === 'modify' => ProviderCardholderReviewStatus::ActionRequired,
            $reviewStatus === 'rejected' || $providerStatus === 'rejected' => ProviderCardholderReviewStatus::Rejected,
            $reviewStatus === 'pending' || $providerStatus === 'pending' => ProviderCardholderReviewStatus::Pending,
            default => ProviderCardholderReviewStatus::Unknown,
        };

        return new ProviderCardholderDTO(
            $providerCardholderId,
            $status,
            $providerStatus ?: null,
            $reviewStatus ?: null,
            $status === ProviderCardholderReviewStatus::ActionRequired ? 'Update the requested card setup information.' : null,
        );
    }

    public function productAvailable(string $providerProductReference, string $cardCurrency): bool
    {
        $data = $this->get('/vcc/openApi/v4/getCardBin', [
            'cardType' => 'recharge',
            'cardFormFactor' => 'virtual_card',
            'cardCurrency' => $cardCurrency,
        ]);

        return collect(is_array($data) ? $data : [])->contains(function (mixed $row) use ($providerProductReference, $cardCurrency): bool {
            if (! is_array($row) || ! hash_equals($providerProductReference, (string) ($row['cardBin'] ?? ''))
                || strtoupper((string) ($row['cardCurrency'] ?? '')) !== strtoupper($cardCurrency)) {
                return false;
            }
            $types = array_map('trim', explode(',', strtolower((string) ($row['cardType'] ?? ''))));
            $factor = strtolower((string) ($row['cardFormFactor'] ?? 'virtual_card'));

            return in_array('recharge', $types, true) && $factor === 'virtual_card';
        });
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        if (! $this->productAvailable($request->providerProductReference, $request->cardCurrency)) {
            throw new ProviderRejectedException('The configured card product is not available from the provider.');
        }
        $data = $this->post('/vcc/openApi/v4/openCard', array_filter([
            'memberId' => $this->memberId,
            'matrixAccount' => $this->matrixAccount,
            'accountId' => $this->accountId,
            'cardBin' => $request->providerProductReference,
            'cardCurrency' => $request->cardCurrency,
            'cardType' => 'recharge',
            'cardFormFactor' => 'virtual_card',
            'cardholderId' => $request->holderReference,
            'requestId' => $request->idempotencyKey,
            'arrivalAmount' => $request->initialLoadAmount,
        ], fn (mixed $value): bool => $value !== null && $value !== ''));
        $returnedRequestId = is_string($data['requestId'] ?? null) ? trim($data['requestId']) : '';
        if ($returnedRequestId === '' || ! hash_equals($request->idempotencyKey, $returnedRequestId)) {
            return new ProviderOperationDTO($request->idempotencyKey, ProviderOperationStatus::Unknown);
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        if (in_array($status, ['failed', 'failure'], true)) {
            return new ProviderOperationDTO($request->idempotencyKey, ProviderOperationStatus::Failed);
        }
        $detail = is_array($data['cardDetail'] ?? null) ? $data['cardDetail'] : $data;
        $card = $this->cards->normalize($detail);
        $cardId = is_array($detail) && is_string($detail['cardId'] ?? null) ? $detail['cardId'] : null;
        $operationStatus = in_array($status, ['succeed', 'succeeded', 'success'], true)
            ? ($card ? ProviderOperationStatus::Succeeded : ProviderOperationStatus::Unknown)
            : ProviderOperationStatus::Processing;

        return new ProviderOperationDTO(
            $request->idempotencyKey,
            $operationStatus,
            $card?->providerCardId ?? $cardId,
            null,
            $card,
        );
    }

    public function queryOperation(string $providerOperationId): ProviderOperationDTO
    {
        try {
            $data = $this->get('/vcc/openApi/v4/getRequestResult', array_filter([
                'memberId' => $this->memberId,
                'requestId' => $providerOperationId,
                'type' => 'apply_card',
            ], fn (mixed $value): bool => $value !== null && $value !== ''));
        } catch (ProviderRejectedException) {
            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Unknown);
        }
        $status = strtolower((string) ($data['status'] ?? ''));
        if (in_array($status, ['failed', 'failure'], true)) {
            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Failed);
        }
        if (! in_array($status, ['succeed', 'succeeded', 'success'], true)) {
            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Processing);
        }
        $detail = is_array($data['cardDetail'] ?? null) ? $data['cardDetail'] : [];
        $card = $this->cards->normalize($detail);
        if (! $card) {
            return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Unknown);
        }

        return new ProviderOperationDTO($providerOperationId, ProviderOperationStatus::Succeeded, $card->providerCardId, null, $card);
    }

    public function getCard(string $providerCardId): ProviderCardDTO
    {
        $card = $this->cards->normalize($this->get('/vcc/openApi/v4/getCardDetail', ['cardId' => $providerCardId]));
        if (! $card) {
            throw new ProviderUnknownResultException('Provider Card details are not safely available.');
        }

        return $card;
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        throw new ProviderUnavailableException('Sensitive Card reveal is not available in this phase.');
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderUnavailableException('Existing Card reload is not available in this phase.');
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderUnavailableException('Card freeze is not available in this phase.');
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderUnavailableException('Card unfreeze is not available in this phase.');
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderUnavailableException('Card cancellation is not available in this phase.');
    }

    public function getBalance(string $providerCardId): ProviderBalanceDTO
    {
        $card = $this->getCard($providerCardId);
        if ($card->providerBalance === null) {
            throw new ProviderUnknownResultException('Provider Card balance is not safely available.');
        }

        return new ProviderBalanceDTO($card->providerBalance, 'USD');
    }

    public function getTransactions(string $providerCardId): array
    {
        throw new ProviderUnavailableException('Card transactions are not available in this phase.');
    }

    /** @return array<string,mixed> */
    private function cardholderPayload(CardholderRequestDTO $request, string $front, ?string $back): array
    {
        return array_filter([
            'memberId' => $this->memberId,
            'matrixAccount' => $this->matrixAccount,
            'email' => $request->email,
            'mobile' => $request->mobile,
            'mobilePrefix' => $request->mobilePrefix,
            'dateOfBirth' => $request->dateOfBirth,
            'firstName' => $request->firstName,
            'lastName' => $request->lastName,
            'certType' => $request->identityDocument->type,
            'portrait' => $front,
            'reverseSide' => $back,
            'nationalityCountryCode' => $request->nationalityCountryCode,
            'residentialAddress' => $request->residentialAddress,
            'residentialCity' => $request->residentialCity,
            'residentialCountryCode' => $request->residentialCountryCode,
            'residentialPostalCode' => $request->residentialPostalCode,
            'residentialState' => $request->residentialState,
            'certCountryCode' => $request->identityDocument->countryCode,
            'certId' => $request->identityDocument->identityNumber,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function uploadDocument(string $contents, string $mimeType, string $side): string
    {
        $this->assertAvailable();
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders($this->authorizationHeaders())
                ->attach('file', $contents, "identity-{$side}.".($mimeType === 'image/png' ? 'png' : 'jpg'))
                ->post($this->url('/file/apiUpload/issuing_cardholder_identity_certificate'));
        } catch (ConnectionException) {
            throw new ProviderUnknownResultException('Provider document upload outcome is unknown.');
        }
        $data = $this->validated($response, false);

        return $this->requiredString($data);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function post(string $path, array $payload): array
    {
        $this->assertAvailable();
        try {
            $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders($this->authorizationHeaders($json))
                ->withBody($json, 'application/json')
                ->post($this->url($path));
        } catch (ConnectionException|JsonException) {
            throw new ProviderUnknownResultException('Provider request outcome is unknown.');
        }

        return $this->validated($response);
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        $this->assertAvailable();
        try {
            $response = Http::timeout($this->timeoutSeconds)
                ->withHeaders($this->authorizationHeaders())
                ->get($this->url($path), $query);
        } catch (ConnectionException) {
            throw new ProviderUnknownResultException('Provider query outcome is unknown.');
        }

        return $this->validated($response);
    }

    /** @return array<string,string> */
    private function authorizationHeaders(?string $body = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-PD-AUTHORIZATION' => 'basic '.base64_encode($this->appId.'/'.$this->appSecret),
        ];
        if ($body !== null && $body !== '') {
            $signature = '';
            $signed = openssl_sign($body, $signature, $this->normalizedPrivateKey(), OPENSSL_ALGO_MD5);
            if (! $signed) {
                throw new ProviderAuthenticationException('Provider request signing is unavailable.');
            }
            $headers['X-PD-SIGN'] = base64_encode($signature);
        }

        return $headers;
    }

    /** @return array<string,mixed> */
    private function validated(Response $response, bool $expectArray = true): array|string
    {
        if ($response->status() === 429) {
            throw new ProviderRateLimitException('Provider rate limit reached.');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new ProviderAuthenticationException('Provider authentication failed.');
        }
        if ($response->serverError()) {
            throw new ProviderUnknownResultException('Provider response outcome is unknown.');
        }
        $json = $response->json();
        if (! is_array($json) || ! isset($json['code'])) {
            throw new ProviderUnknownResultException('Provider returned an unrecognized response.');
        }
        if ((string) $json['code'] !== '0000') {
            throw new ProviderRejectedException('Provider rejected the request.');
        }
        $data = $json['data'] ?? null;
        if ($expectArray && ! is_array($data)) {
            throw new ProviderUnknownResultException('Provider returned incomplete data.');
        }
        if (! $expectArray && ! is_string($data)) {
            throw new ProviderUnknownResultException('Provider returned incomplete data.');
        }

        return $data;
    }

    private function requiredString(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            throw new ProviderUnknownResultException('Provider returned incomplete data.');
        }

        return trim($value);
    }

    private function assertAvailable(): void
    {
        if (! $this->available()) {
            throw new ProviderUnavailableException('PhotonPay Card issuing is not configured.');
        }
    }

    private function normalizedPrivateKey(): string
    {
        return str_replace('\\n', "\n", trim($this->privateKey));
    }

    private function url(string $path): string
    {
        return rtrim($this->baseUrl, '/').'/'.ltrim($path, '/');
    }
}
