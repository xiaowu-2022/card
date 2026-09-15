<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\ProviderCardholder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Card\Services\CardholderMaterials;
use App\Support\Errors\DomainException;
use libphonenumber\PhoneNumberUtil;

final readonly class UserCardholderDetailsQuery
{
    private const FIELDS = [
        'email' => 'email', 'dateOfBirth' => 'date_of_birth',
        'nationalityCountryCode' => 'nationality_country_code',
        'residentialCountryCode' => 'residential_country_code', 'residentialState' => 'residential_state',
        'residentialCity' => 'residential_city', 'residentialAddress' => 'residential_address',
        'residentialPostalCode' => 'residential_postal_code', 'mobile' => 'mobile', 'mobilePrefix' => 'mobile_prefix',
    ];

    public function __construct(private CardManagementAccess $access, private CardholderMaterials $materials) {}

    /** Read only this owned card's saved fields and confirmed edits; never account KYC or documents. */
    public function execute(string $tenantId, string $userId, string $cardId): array
    {
        $card = $this->access->card($tenantId, $userId, $cardId);
        $holder = ProviderCardholder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
            ->whereKey($card->provider_cardholder_id)->firstOrFail();
        try {
            if (! $holder->materials_encrypted) {
                throw new \RuntimeException;
            }
            $envelope = json_decode($this->materials->decrypt($holder->materials_encrypted), true, flags: JSON_THROW_ON_ERROR);
            $fields = array_intersect_key($envelope['fields'], array_flip(array_values(self::FIELDS)));
            // Legacy cards can share a holder. Confirmed edits belong to that exact scoped holder.
            $cardIds = UserCard::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('provider_cardholder_id', $holder->id)->select('id');
            $edits = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->whereIn('card_id', $cardIds)->where('kind', 'HOLDER_UPDATE')->where('status', 'SUCCEEDED')
                ->orderBy('created_at')->orderBy('id')->get(['holder_changes_encrypted']);
            foreach ($edits as $edit) {
                $changes = json_decode($this->materials->decrypt($edit->holder_changes_encrypted), true, flags: JSON_THROW_ON_ERROR);
                foreach (self::FIELDS as $providerField => $field) {
                    if (isset($changes[$providerField]) && is_string($changes[$providerField])) {
                        $fields[$field] = $changes[$providerField];
                    }
                }
            }
            $fields['mobile_country_code'] = '';
            if (! empty($fields['mobile']) && ! empty($fields['mobile_prefix'])) {
                $phone = PhoneNumberUtil::getInstance();
                $fields['mobile_country_code'] = $phone->getRegionCodeForNumber($phone->parse('+'.$fields['mobile_prefix'].$fields['mobile'], null)) ?? '';
            }
            unset($fields['mobile_prefix']);

            return array_map(static fn ($value): string => is_string($value) ? $value : '', $fields);
        } catch (\Throwable) {
            // Decryption/phone errors must never expose private material or provider details.
            throw new DomainException('CARD_HOLDER_DETAILS_UNAVAILABLE', 'Cardholder information could not be loaded. Please close and try again.', 503);
        }
    }
}
