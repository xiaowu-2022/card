<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Services\CardholderMaterials;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use App\Support\Errors\DomainException;
use libphonenumber\PhoneNumberUtil;

final readonly class ReadUnissuedCardholderMaterialsQuery
{
    public function __construct(private CardholderMaterials $materials) {}

    public function execute(string $tenantId, string $userId, string $applicationId): array
    {
        RefundCardPolicy::assertAllowed($tenantId, $userId);
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $user = User::query()->where('tenant_id', $tenantId)->whereKey($userId)->firstOrFail();
        $holder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->whereKey($applicationId)->firstOrFail();
        if ($tenant->status->value !== 'ACTIVE' || $user->status->value !== 'ACTIVE'
            || ! in_array($holder->status->value, ['READY', 'ACTION_REQUIRED'], true)
            || ! $holder->request_id || ! $holder->provider_cardholder_id
            || CardIssueOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('provider_cardholder_id', $holder->id)->exists()) {
            throw new DomainException('CARD_MATERIALS_LOCKED', 'Cardholder materials cannot be edited while an application is being processed.', 409);
        }
        try {
            $saved = json_decode($this->materials->decrypt($holder->materials_encrypted), true, flags: JSON_THROW_ON_ERROR)['fields'];
            $fields = array_intersect_key($saved, array_flip(['legal_first_name', 'legal_last_name', 'email', 'date_of_birth', 'nationality_country_code',
                'cardholder_name_abbreviation', 'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code', 'document_type', 'mobile']));
            $fields['cardholder_name_abbreviation'] = $saved['cardholder_name_abbreviation'] ?? '';
            $fields['mobile_country_code'] = '';
            if (! empty($saved['mobile']) && ! empty($saved['mobile_prefix'])) {
                $phone = PhoneNumberUtil::getInstance();
                $fields['mobile_country_code'] = $phone->getRegionCodeForNumber($phone->parse('+'.$saved['mobile_prefix'].$saved['mobile'], null)) ?? '';
            }

            return array_map(static fn ($value): string => is_string($value) ? $value : '', $fields);
        } catch (\Throwable) {
            throw new DomainException('CARD_HOLDER_DETAILS_UNAVAILABLE', 'Cardholder information could not be loaded. Please close and try again.', 503);
        }
    }
}
