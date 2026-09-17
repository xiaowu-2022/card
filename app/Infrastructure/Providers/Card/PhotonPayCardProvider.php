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
use App\Domain\CardProvider\DTOs\ProviderTransactionPageDTO;
use App\Domain\CardProvider\Enums\ProviderCardholderReviewStatus;
use App\Domain\CardProvider\Enums\ProviderOperationStatus;
use App\Domain\CardProvider\Exceptions\ProviderAuthenticationException;
use App\Domain\CardProvider\Exceptions\ProviderRateLimitException;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\CardProvider\Exceptions\ProviderUnavailableException;
use App\Domain\CardProvider\Exceptions\ProviderUnknownResultException;
use App\Domain\CardProvider\ProviderReference;
use App\Support\Logging\PhotonPayLog;
use Brick\Math\BigDecimal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use JsonException;

final class PhotonPayCardProvider implements CardProviderInterface
{
    use PhotonPayCardManagement;

    private ?string $accessToken = null;

    private ?string $selectedScheme = null;

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
        private readonly bool $tokenAuthentication = false,
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

        return $this->addedCardholder($data);
    }

    public function updateCardholder(CardholderRequestDTO $request): ProviderCardholderDTO
    {
        $this->assertLiveReference($request->providerCardholderId);
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

        return $this->addedCardholder($data, $request->providerCardholderId);
    }

    /** @param array<string,mixed> $data */
    private function addedCardholder(array $data, ?string $expectedId = null): ProviderCardholderDTO
    {
        $id = $this->requiredString($data['cardholderId'] ?? $expectedId);
        if (ProviderReference::isTest($id)) {
            throw new ProviderUnknownResultException('Provider returned an unusable Cardholder identity.');
        }
        if ($expectedId !== null && ! hash_equals($expectedId, $id)) {
            throw new ProviderUnknownResultException('Provider Cardholder identity did not match.');
        }
        $status = strtolower(is_string($data['status'] ?? null) ? $data['status'] : '');
        $review = strtolower(is_string($data['cardholderReviewStatus'] ?? null) ? $data['cardholderReviewStatus'] : '');
        if (array_intersect([$status, $review], ['disabled', 'rejected', 'failed', 'modify'])) {
            throw new ProviderRejectedException('Provider could not add this Cardholder.');
        }

        // READY means the add/edit operation succeeded with a usable identity, not a separate review approval.
        // In particular, reviewStatus=pending in a successful add response does not create a local review step.
        return new ProviderCardholderDTO($id, ProviderCardholderReviewStatus::Ready);
    }

    public function getCardholder(string $providerCardholderId): ProviderCardholderDTO
    {
        $this->assertLiveReference($providerCardholderId);
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
            $reviewStatus === 'modify' || $providerStatus === 'modify' => ProviderCardholderReviewStatus::ActionRequired,
            in_array($reviewStatus, ['rejected', 'failed'], true) || in_array($providerStatus, ['rejected', 'failed'], true) => ProviderCardholderReviewStatus::Rejected,
            in_array($providerStatus, ['normal', 'pending'], true) => ProviderCardholderReviewStatus::Ready,
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
        $this->selectedScheme = null;
        $this->assertLiveReference($providerProductReference);
        $data = $this->get('/vcc/openApi/v4/getCardBin', [
            'cardType' => 'recharge',
            'cardFormFactor' => 'virtual_card',
            'cardCurrency' => $cardCurrency,
        ]);

        return collect(is_array($data) ? $data : [])->contains(function (mixed $row) use ($providerProductReference, $cardCurrency): bool {
            if (! is_array($row) || ! hash_equals($providerProductReference, (string) ($row['cardBin'] ?? ''))) {
                return false;
            }
            foreach (['cardCurrency', 'cardType', 'cardFormFactor'] as $field) {
                if (! is_string($row[$field] ?? null)) {
                    return false;
                }
            }
            $currencies = array_map('trim', explode(',', strtoupper($row['cardCurrency'])));
            $types = array_map('trim', explode(',', strtolower((string) ($row['cardType'] ?? ''))));
            $factors = array_map('trim', explode(',', strtolower($row['cardFormFactor'])));

            $eligible = in_array(strtoupper($cardCurrency), $currencies, true)
                && in_array('recharge', $types, true) && in_array('virtual_card', $factors, true);
            if ($eligible && in_array($row['cardScheme'] ?? null, ['Discover', 'MasterCard'], true)) {
                $this->selectedScheme = $row['cardScheme'];
            }

            return $eligible;
        });
    }

    public function issueCard(IssueCardRequestDTO $request): ProviderOperationDTO
    {
        $this->assertLiveReference($request->holderReference);
        if (! $this->productAvailable($request->providerProductReference, $request->cardCurrency)) {
            throw new ProviderRejectedException('The configured card product is not available from the provider.');
        }
        $data = $this->post('/vcc/openApi/v4/openCard', array_filter([
            'memberId' => $this->memberId,
            'matrixAccount' => $this->matrixAccount,
            'accountId' => $this->accountId,
            'cardBin' => $request->providerProductReference,
            'cardScheme' => $this->selectedScheme,
            'cardCurrency' => $request->cardCurrency,
            'cardType' => 'recharge',
            'cardFormFactor' => 'virtual_card',
            'cardholderId' => $request->holderReference,
            'requestId' => $request->idempotencyKey,
            'arrivalAmount' => (string) BigDecimal::of($request->initialLoadAmount)->toScale(2),
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
        $this->assertLiveReference($providerOperationId);
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
        $this->assertLiveReference($providerCardId);
        $data = $this->get('/vcc/openApi/v4/getCardDetail', ['cardId' => $providerCardId]);
        $card = $this->cards->normalize($data);
        if (! $card || ! hash_equals($providerCardId, $card->providerCardId) || ! is_string($data['cardStatus'] ?? null) || $data['cardStatus'] === '') {
            throw new ProviderUnknownResultException('Provider Card details are not safely available.');
        }

        return $card;
    }

    public function revealCard(string $providerCardId): ProviderSensitiveCardDTO
    {
        $this->assertLiveReference($providerCardId);
        $data = $this->managementCall('GET', '/vcc/openApi/v4/getCvv', ['cardId' => $providerCardId]);
        $this->managementRequire(($data['cardId'] ?? null) === $providerCardId
            && is_string($data['cardNo'] ?? null) && preg_match('/^[0-9]{12,19}$/', $data['cardNo']) === 1
            && is_string($data['cvv'] ?? null) && preg_match('/^[0-9]{3,4}$/', $data['cvv']) === 1
            && is_string($data['expirationDate'] ?? null) && preg_match('/^(0[1-9]|1[0-2])\/[0-9]{2}$/', $data['expirationDate']) === 1);

        return new ProviderSensitiveCardDTO($data['cardNo'], $data['cvv'], false, $data['expirationDate']);
    }

    public function loadCard(string $providerCardId, string $amount, string $assetCode, string $idempotencyKey): ProviderOperationDTO
    {
        throw new ProviderUnavailableException('Reload requires a confirmed provider quotation.');
    }

    public function freezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        $this->assertLiveReference($providerCardId);
        $this->managementCall('POST', '/vcc/openApi/v4/freezeCard', ['cardId' => $providerCardId, 'requestId' => $idempotencyKey, 'status' => 'freeze'], allowEmpty: true);

        return new ProviderOperationDTO($idempotencyKey, ProviderOperationStatus::Processing, $providerCardId);
    }

    public function unfreezeCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        $this->assertLiveReference($providerCardId);
        $this->managementCall('POST', '/vcc/openApi/v4/freezeCard', ['cardId' => $providerCardId, 'requestId' => $idempotencyKey, 'status' => 'unfreeze'], allowEmpty: true);

        return new ProviderOperationDTO($idempotencyKey, ProviderOperationStatus::Processing, $providerCardId);
    }

    public function cancelCard(string $providerCardId, string $idempotencyKey): ProviderOperationDTO
    {
        $this->assertLiveReference($providerCardId);
        $this->managementCall('POST', '/vcc/openApi/v4/cancelCard', ['cardId' => $providerCardId], allowEmpty: true);

        return new ProviderOperationDTO($idempotencyKey, ProviderOperationStatus::Processing, $providerCardId);
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
        throw new ProviderUnavailableException('Use the paginated card transaction query.');
    }

    public function getTransactionPage(string $providerCardId, int $page, int $pageSize): ProviderTransactionPageDTO
    {
        return PhotonPayLog::run('request', ['method' => 'GET', 'endpoint' => '/vcc/openApi/v4/pagingVccTradeOrder', 'page' => $page, 'page_size' => $pageSize, 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace) use ($providerCardId, $page, $pageSize): ProviderTransactionPageDTO {
            $this->assertAvailable();
            $this->assertLiveReference($providerCardId);
            if (trim($providerCardId) === '' || $page < 1 || $page > 100000 || $pageSize < 1 || $pageSize > 100) {
                throw new ProviderRejectedException('Invalid transaction query.');
            }
            try {
                $response = Http::timeout($this->timeoutSeconds)->withHeaders($this->authorizationHeaders())
                    ->get($this->url('/vcc/openApi/v4/pagingVccTradeOrder'), array_filter([
                        'memberId' => $this->memberId, 'matrixAccount' => $this->matrixAccount,
                        'cardId' => $providerCardId, 'cardType' => 'recharge', 'cardFormFactor' => 'virtual_card',
                        'pageIndex' => $page, 'pageSize' => $pageSize,
                    ], fn (mixed $value): bool => $value !== null && $value !== ''));
                $trace->response($response);
                $normalizer = new PhotonPayTransactionNormalizer;
                $decoded = $normalizer->decode($response->body());
                $this->validated($response, decoded: $decoded);

                $verifiedCurrency = null;
                if (collect($decoded['data'] ?? [])->contains(fn ($row) => is_array($row) && ! isset($row['cardCurrency']))) {
                    $verifiedCurrency = $this->getCard($providerCardId)->assetCode;
                }

                return $normalizer->page($decoded, $providerCardId, $page, $pageSize, $verifiedCurrency);
            } catch (ConnectionException|JsonException $failure) {
                $trace->failedBecause($failure);
                throw new ProviderUnknownResultException('Provider transactions could not be confirmed.');
            }
        });
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
            // Omitted when not supplied; never send a placeholder or derive an account identity number.
            'certId' => $request->identityDocument->identityNumber,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function uploadDocument(string $contents, string $mimeType, string $side): string
    {
        return PhotonPayLog::run('request', ['method' => 'POST', 'endpoint' => '/file/apiUpload/issuing_cardholder_identity_certificate', 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace) use ($contents, $mimeType, $side): string {
            $this->assertAvailable();
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withHeaders($this->authorizationHeaders())
                    ->attach('file', $contents, "identity-{$side}.".($mimeType === 'image/png' ? 'png' : 'jpg'))
                    ->post($this->url('/file/apiUpload/issuing_cardholder_identity_certificate'));
            } catch (ConnectionException $failure) {
                $trace->failedBecause($failure);
                throw new ProviderUnknownResultException('Provider document upload outcome is unknown.');
            }
            $trace->response($response);
            $data = $this->validated($response, false);

            return $this->requiredString($data);
        });
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function post(string $path, array $payload): array
    {
        return PhotonPayLog::run('request', ['method' => 'POST', 'endpoint' => $path, 'provider_request_ref' => PhotonPayLog::reference(isset($payload['requestId']) && is_string($payload['requestId']) ? $payload['requestId'] : null), 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace) use ($path, $payload): array {
            $this->assertAvailable();
            try {
                $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                $response = Http::timeout($this->timeoutSeconds)
                    ->withHeaders($this->authorizationHeaders($json))
                    ->withBody($json, 'application/json')
                    ->post($this->url($path));
            } catch (ConnectionException|JsonException $failure) {
                $trace->failedBecause($failure);
                throw new ProviderUnknownResultException('Provider request outcome is unknown.');
            }

            $trace->response($response);

            return $this->validated($response);
        });
    }

    /** @param array<string,mixed> $query @return array<string,mixed> */
    private function get(string $path, array $query): array
    {
        return PhotonPayLog::run('request', ['method' => 'GET', 'endpoint' => $path, 'provider_request_ref' => PhotonPayLog::reference(isset($query['requestId']) && is_string($query['requestId']) ? $query['requestId'] : null), 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace) use ($path, $query): array {
            $this->assertAvailable();
            try {
                $response = Http::timeout($this->timeoutSeconds)
                    ->withHeaders($this->authorizationHeaders())
                    ->get($this->url($path), $query);
            } catch (ConnectionException $failure) {
                $trace->failedBecause($failure);
                throw new ProviderUnknownResultException('Provider query outcome is unknown.');
            }

            $trace->response($response);

            return $this->validated($response);
        });
    }

    /** @return array<string,string> */
    private function authorizationHeaders(?string $body = null): array
    {
        $headers = [
            'Accept' => 'application/json',
            'X-PD-AUTHORIZATION' => 'basic '.base64_encode($this->appId.'/'.$this->appSecret),
        ];
        if ($this->tokenAuthentication) {
            if ($this->accessToken === null) {
                $this->accessToken = PhotonPayLog::run('token', ['method' => 'POST', 'endpoint' => '/oauth2/token/accessToken', 'connection_ref' => PhotonPayLog::reference($this->baseUrl."\0".$this->appId)], function (PhotonPayLog $trace): string {
                    $cache = Cache::store(app()->environment('testing') ? 'array' : 'file');
                    $key = 'photonpay-issuing-token:'.hash('sha256', $this->baseUrl."\0".$this->appId."\0".$this->appSecret);
                    $encrypted = $cache->lock($key.':lock', 30)->block(5, function () use ($cache, $key, $trace): string {
                        $saved = $cache->get($key);
                        if (is_string($saved)) {
                            $trace->cacheHit();

                            return $saved;
                        }
                        $response = Http::connectTimeout(5)->timeout($this->timeoutSeconds)->withoutRedirecting()
                            ->withHeaders(['Authorization' => 'basic '.base64_encode($this->appId.'/'.$this->appSecret)])
                            ->withBody('', 'application/json')->post($this->url('/oauth2/token/accessToken'));
                        $trace->response($response);
                        $auth = $this->validated($response);
                        $expiry = $auth['expiresIn'] ?? null;
                        if (! is_string($expiry) || ! ctype_digit($expiry) || strlen($expiry) > 13) {
                            throw new ProviderAuthenticationException('Token expiry unavailable.');
                        }
                        $ttl = min(6600, intdiv((int) $expiry, 1000) - time() - 30);
                        if ($ttl <= 0) {
                            throw new ProviderAuthenticationException('Token expired.');
                        }
                        $saved = Crypt::encryptString($this->requiredString($auth['token'] ?? null));
                        $cache->put($key, $saved, $ttl);

                        return $saved;
                    });

                    return Crypt::decryptString($encrypted);
                });
            }
            unset($headers['X-PD-AUTHORIZATION']);
            $headers['X-PD-TOKEN'] = $this->accessToken;
        }
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
    private function validated(Response $response, bool $expectArray = true, ?array $decoded = null): array|string
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
        $json = $decoded ?? (new PhotonPayTransactionNormalizer)->decode($response->body());
        if (! is_array($json) || ! isset($json['code'])) {
            throw new ProviderUnknownResultException('Provider returned an unrecognized response.');
        }
        if ((string) $json['code'] !== '0000') {
            throw new ProviderRejectedException('Provider rejected the request.');
        }
        if (! $response->successful()) {
            throw new ProviderUnknownResultException('Provider returned an inconsistent response.');
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

    private function assertLiveReference(?string $reference): void
    {
        if (ProviderReference::isTest($reference)) {
            throw new ProviderUnavailableException('Test references cannot be used with PhotonPay.');
        }
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
