<?php

namespace App\Application\Card;

use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\ProviderReference;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Support\Errors\DomainException;

final class LiveCardReferenceGuard
{
    public static function forProduct(CardProduct $product, ?string ...$references): void
    {
        $router = app(CardProductProviderRouter::class);
        if ($router->isLocalMock($product) && LocalCardSimulation::enabled()) {
            foreach ($references as $reference) {
                if ($reference !== null && ! ProviderReference::isTest($reference)) {
                    throw new DomainException('CARD_TEST_REFERENCE', 'Card identity does not match its merchant connection.', 409);
                }
            }

            return;
        }
        if ($router->isPhotonPayAccount($product)) {
            foreach ($references as $reference) {
                if (ProviderReference::isTest($reference)) {
                    throw new DomainException('CARD_TEST_REFERENCE', 'Mock identities cannot be used by PhotonPay.', 409);
                }
            }

            return;
        }
        if (config('card-provider.driver') === 'directory') {
            foreach ($references as $reference) {
                if (ProviderReference::isTest($reference)) {
                    throw new DomainException('CARD_TEST_REFERENCE', 'Card identity does not match its merchant connection.', 409);
                }
            }

            return;
        }
        self::check(...$references);
    }

    public static function check(?string ...$references): void
    {
        if (config('card-provider.driver') !== 'directory' && LocalCardSimulation::active()) {
            foreach ($references as $reference) {
                if ($reference !== null && ! ProviderReference::isTest($reference)) {
                    throw new DomainException('CARD_TEST_REFERENCE', 'Real card identities cannot be used in local simulation.', 409);
                }
            }

            return;
        }
        if (app()->environment('testing')) {
            return;
        }
        foreach ($references as $reference) {
            if (ProviderReference::isTest($reference)) {
                throw new DomainException('CARD_TEST_REFERENCE', 'This test card setup cannot be used for live card issuing. Please contact support.', 409);
            }
        }
    }
}
