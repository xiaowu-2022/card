<?php

namespace App\Application\Card;

use App\Application\CardProviderDirectory\PhotonPayAccounts;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\U1CardProvider;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class CardProductProviderRouter
{
    public function __construct(private CardProviderInterface $legacyProvider) {}

    public function forProduct(CardProduct $product, string $formFactor = 'virtual_card'): CardProviderInterface
    {
        if ($product->card_provider_reference_id && CardProviderReference::includingDeleted()->whereKey($product->card_provider_reference_id)->exists()) {
            $u1 = DB::table('u1_connections')->where('reference_id', $product->card_provider_reference_id)->first();
            if ($u1) {
                return $formFactor === 'virtual_card' ? new U1CardProvider($u1) : new UnavailableCardProvider;
            }
        }
        if ($product->cardProviderReference?->photonpay_issuing_encrypted && ! $product->cardProviderReference?->photonpay_migration_error) {
            try {
                if (! $product->cardProviderReference->photonpay_identity || $product->cardProviderReference->photonpay_check_status !== 'VERIFIED') {
                    return new UnavailableCardProvider;
                }
                $c = json_decode(Crypt::decryptString($product->cardProviderReference->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
                if (! in_array($c['base_url'], PhotonPayAccounts::BASES, true)) {
                    return new UnavailableCardProvider;
                }

                return new PhotonPayCardProvider($c['base_url'], $c['app_id'], $c['app_secret'], $c['private_key'], $c['account_id'], $c['member_id'], $c['matrix_account'] ?? null, 20, new PhotonPayCardResponseNormalizer, true, $formFactor, hash('sha256', $product->cardProviderReference->photonpay_issuing_encrypted));
            } catch (\Throwable $failure) {
                PhotonPayLog::write('connection.unavailable', ['resource_id' => $product->card_provider_reference_id, 'failure' => PhotonPayLog::failure($failure)], true);

                return new UnavailableCardProvider;
            }
        }
        if ($this->isLocalMock($product) && config('card-provider.driver') === 'directory' && LocalCardSimulation::enabled()) {
            return new LocalMockCardProvider;
        }
        if ($this->isLocalMock($product) && LocalCardSimulation::enabled() && $this->legacyProvider instanceof LocalMockCardProvider) {
            return $this->legacyProvider;
        }

        if ($formFactor === 'physical_card') {
            return $product->provider === 'PHOTONPAY' && $product->card_provider_reference_id === null && $this->legacyProvider instanceof PhotonPayCardProvider
                ? $this->legacyProvider->forFormFactor($formFactor) : new UnavailableCardProvider;
        }

        // Unintegrated directory records never fall back to the legacy driver.
        return $product->provider === 'PHOTONPAY' && $product->card_provider_reference_id === null
            ? $this->legacyProvider : new UnavailableCardProvider;
    }

    public function isSandbox(CardProduct $product): bool
    {
        return $this->isPhotonPayAccount($product)
            && ($product->cardProviderReference->photonpay_identity['base_url'] ?? '') === PhotonPayAccounts::BASES['sandbox'];
    }

    public function isU1Account(CardProduct $product): bool
    {
        return $product->card_provider_reference_id !== null && $product->cardProviderReference !== null
            && DB::table('u1_connections')->where('reference_id', $product->card_provider_reference_id)->exists();
    }

    public function managementCapabilities(UserCard $card, array $existing): array
    {
        if (! $this->isU1Account($card->product)) {
            return $existing;
        }
        $c = DB::table('u1_connections')->where('reference_id', $card->product->card_provider_reference_id)->firstOrFail();
        $allowed = ['reveal', 'transactions'];
        if ($c->check_status === 'VERIFIED' && $card->provider_status === 'normal') {
            $allowed[] = 'load';
            $group = collect(json_decode($c->group_catalog ?? '[]', true))->firstWhere('groupNo', $card->product->provider_product_ref);
            if (($group['model'] ?? null) === 'SHARE') {
                $allowed[] = 'return';
            }
        }

        return array_values(array_unique([...array_intersect($existing, $allowed), ...($card->provider_status === 'normal' && in_array('reveal', $existing, true) ? ['otp'] : [])]));
    }

    public function isPhotonPayAccount(CardProduct $product): bool
    {
        return $product->card_provider_reference_id !== null
            && $product->cardProviderReference?->runtime_driver === 'UNCONFIGURED'
            && is_string($product->cardProviderReference?->photonpay_issuing_encrypted)
            && ! $product->cardProviderReference?->photonpay_migration_error;
    }

    public function forCard(UserCard $card): CardProviderInterface
    {
        return $this->forProduct($card->product, $card->form_factor ?? 'virtual_card');
    }

    public function isLocalMock(CardProduct $product): bool
    {
        return $product->provider === 'UNCONFIGURED' && $product->card_provider_reference_id !== null
            && $product->cardProviderReference?->runtime_driver === 'LOCAL_MOCK';
    }

    public function productReference(CardProduct $product): string
    {
        // Keep the displayed BIN separate from the simulator's stable, explicitly test-only identity.
        return $this->isLocalMock($product) ? 'MOCK-LOCAL-PRODUCT-'.$product->id : $product->provider_product_ref;
    }

    public function assertNewBusiness(CardProduct $product): void
    {
        $query = CardProviderReference::whereKey($product->card_provider_reference_id);
        $account = DB::transactionLevel() > 0 ? $query->lockForUpdate()->first() : $query->first();
        if ($product->card_provider_reference_id !== null && ! $account) {
            throw new DomainException('CARD_ACCOUNT_PAUSED', 'This PhotonPay account is not accepting new business.', 409);
        }
        if ($account && $this->isU1Account($product)) {
            $c = DB::table('u1_connections')->where('reference_id', $account->id)->firstOrFail();
            $group = collect(json_decode($c->group_catalog ?? '[]', true))->firstWhere('groupNo', $product->provider_product_ref);
            if (($group['bin'] ?? null) !== $product->source_bin || $c->check_status !== 'VERIFIED' || ! $this->forProduct($product)->productAvailable($product->provider_product_ref, $product->card_currency)) {
                throw new DomainException('CARD_ACCOUNT_PAUSED', 'This U1 account is not accepting new business.', 409);
            }
        }
        if ($account?->photonpay_issuing_encrypted && (! $account->photonpay_enabled || $account->photonpay_migration_error)) {
            throw new DomainException('CARD_ACCOUNT_PAUSED', 'This PhotonPay account is not accepting new business.', 409);
        }
    }

    public function assertConfigured(CardProduct $product): void
    {
        if (! $this->forProduct($product)->available()) {
            throw new DomainException('CARD_PROVIDER_UNAVAILABLE', 'Card setup is currently unavailable.', 503);
        }
    }
}
