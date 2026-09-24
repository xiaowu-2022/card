<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardRecipientApplication;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardRecipientMaterials;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProduct\Models\TenantCardProductConfig;
use App\Domain\CardProvider\Contracts\PhysicalCardProviderInterface;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\Kyc\Enums\KycUserStatus;
use App\Domain\Kyc\Services\KycStatusService;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;

final class CreateCardRecipientAction
{
    public function execute(string $tenantId, string $userId, #[\SensitiveParameter] array $input): CardRecipientApplication
    {
        $fields = array_intersect_key($input, array_flip(['recipientFirstName', 'recipientLastName', 'mobilePrefix', 'mobile', 'country', 'state', 'city', 'addressLine1', 'addressLine2', 'addressLine3', 'postalCode']));
        $fields = array_filter($fields, fn ($v) => $v !== null && $v !== '');
        ksort($fields);
        $cipher = app(CardRecipientMaterials::class);
        $hash = $cipher->fingerprint($tenantId, $userId, $input['cardholder_application_id'], json_encode($fields, JSON_THROW_ON_ERROR));
        [$recipient, $send] = DB::transaction(function () use ($tenantId, $userId, $input, $fields, $hash, $cipher): array {
            $tenant = Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $user = User::where('tenant_id', $tenantId)->whereKey($userId)->lockForUpdate()->firstOrFail();
            RefundCardPolicy::assertAllowed($tenantId, $userId);
            if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE') {
                throw new DomainException('CARD_SETUP_UNAVAILABLE', 'Card setup requires an active account.', 403);
            }
            if (app(KycStatusService::class)->forUser($tenantId, $userId) !== KycUserStatus::Approved) {
                throw new DomainException('KYC_NOT_APPROVED', 'Approved identity verification is required before Card setup.', 403);
            }
            $existing = CardRecipientApplication::where('tenant_id', $tenantId)->where('user_id', $userId)->where('request_id', $input['request_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw new DomainException('IDEMPOTENCY_CONFLICT', 'This request was already used with different details.', 409);
                }

                return [$existing, false];
            }
            $holder = ProviderCardholder::where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($input['cardholder_application_id'])->lockForUpdate()->firstOrFail();
            if ($holder->form_factor !== 'physical_card' || $holder->status->value !== 'READY' || CardIssueOrder::where('provider_cardholder_id', $holder->id)->exists()) {
                throw new DomainException('CARD_RECIPIENT_UNAVAILABLE', 'Complete the physical cardholder application first.', 409);
            }
            $pending = CardRecipientApplication::where('cardholder_application_id', $holder->id)->where('status', '!=', 'FAILED')->first();
            if ($pending) {
                throw new DomainException('CARD_RECIPIENT_PENDING', 'Recipient creation is already in progress or completed. Do not submit again.', 409);
            }
            $product = CardProduct::whereKey($holder->card_product_id)->lockForUpdate()->firstOrFail();
            if ($product->archived_at !== null || $product->status->value !== 'ACTIVE' || $product->card_currency !== 'USD' || $product->card_type !== 'REGULAR' || ! TenantCardProductConfig::where('tenant_id', $tenantId)->where('card_product_id', $product->id)->where('status', 'ACTIVE')->exists() || ! in_array('physical_card', $product->supported_form_factors, true)) {
                throw new DomainException('CARD_FORM_UNAVAILABLE', 'This card type is not available.', 409);
            }
            app(CardProductProviderRouter::class)->assertNewBusiness($product);
            $recipient = new CardRecipientApplication;
            $recipient->forceFill(['tenant_id' => $tenantId, 'user_id' => $userId, 'card_product_id' => $product->id,
                'card_provider_reference_id' => $product->card_provider_reference_id, 'cardholder_application_id' => $holder->id,
                'request_id' => $input['request_id'], 'request_hash' => $hash, 'materials_encrypted' => $cipher->encrypt(json_encode($fields, JSON_THROW_ON_ERROR)),
                'status' => 'SUBMITTING'])->save();
            app(AuditLogger::class)->record($tenantId, 'USER', $userId, 'CARD_RECIPIENT_SUBMITTED', 'card_recipient_application', $recipient->id);

            return [$recipient, true];
        });
        if (! $send) {
            return $recipient;
        }
        $provider = app(CardProductProviderRouter::class)->forProduct(CardProduct::findOrFail($recipient->card_product_id), 'physical_card');
        try {
            if (! $provider instanceof PhysicalCardProviderInterface) {
                throw new ProviderRejectedException('Physical cards unavailable.');
            }
            $id = $provider->addRecipient($fields);
            // Save the exact returned ID before a second network request; never lose identity on query failure.
            $recipient->forceFill(['provider_recipient_id' => $id, 'status' => 'UNKNOWN'])->save();
            if ($provider->recipientAvailable($id)) {
                $recipient->forceFill(['status' => 'READY'])->save();
            }
        } catch (ProviderRejectedException) {
            $recipient->forceFill(['status' => $recipient->provider_recipient_id ? 'UNKNOWN' : 'FAILED'])->save();
        } catch (\Throwable) {
            $recipient->forceFill(['status' => 'UNKNOWN'])->save();
        }
        app(AuditLogger::class)->record($tenantId, 'USER', $userId, 'CARD_RECIPIENT_RESULT', 'card_recipient_application', $recipient->id, null, ['status' => $recipient->status]);

        return $recipient;
    }
}
