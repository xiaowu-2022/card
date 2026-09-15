<?php

namespace App\Application\Card;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayCardResponseNormalizer;
use App\Infrastructure\Providers\Card\UnavailableCardProvider;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class CardProductProviderRouter
{
    public function __construct(private CardProviderInterface $legacyProvider) {}

    public function forProduct(CardProduct $product): CardProviderInterface
    {
        if ($this->isSandbox($product)) {
            try {
                $c = json_decode(Crypt::decryptString($product->cardProviderReference->photonpay_issuing_encrypted), true, 512, JSON_THROW_ON_ERROR);
                if ($c['base_url'] !== 'https://x-api.sandbox.photontech.cc') {
                    return new UnavailableCardProvider;
                }

                return new PhotonPayCardProvider($c['base_url'], $c['app_id'], $c['app_secret'], $c['private_key'], $c['account_id'], $c['member_id'], null, 20, new PhotonPayCardResponseNormalizer, true);
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

        // Unintegrated directory records never fall back to the legacy driver.
        return $product->provider === 'PHOTONPAY' && $product->card_provider_reference_id === null
            ? $this->legacyProvider : new UnavailableCardProvider;
    }

    public function isSandbox(CardProduct $product): bool
    {
        return (config('card-provider.driver') === 'directory' || (app()->environment('local', 'testing')
            && in_array(DB::connection()->getDatabaseName(), ['card_mock', 'card_ui_test'], true)))
            && $product->provider === 'UNCONFIGURED'
            && $product->cardProviderReference?->runtime_driver === 'UNCONFIGURED'
            && is_string($product->cardProviderReference?->photonpay_issuing_encrypted);
    }

    public function forCard(UserCard $card): CardProviderInterface
    {
        return $this->forProduct($card->product);
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

    public function assertConfigured(CardProduct $product): void
    {
        if (! $this->forProduct($product)->available()) {
            throw new DomainException('CARD_PROVIDER_UNAVAILABLE', 'Card setup is currently unavailable.', 503);
        }
    }
}
