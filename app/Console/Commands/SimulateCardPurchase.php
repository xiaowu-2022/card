<?php

namespace App\Console\Commands;

use App\Application\Card\ReceiveCardNotificationAction;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

final class SimulateCardPurchase extends Command
{
    protected $signature = 'cards:mock-purchase {card : Local application card UUID} {amount : Exact USD decimal} {request : Stable UUID; reuse for duplicate delivery}';

    protected $description = 'Simulate an isolated local card purchase and verified notification (never live cards)';

    public function handle(): int
    {
        if (! app()->environment('local') || ! LocalCardSimulation::active() || ! Str::isUuid($this->argument('card')) || ! Str::isUuid($this->argument('request'))) {
            $this->error('Requires explicit local Mock, an isolated database and valid card/request UUIDs.');

            return self::FAILURE;
        }
        $card = UserCard::query()->whereKey($this->argument('card'))->firstOrFail();
        $provider = app(CardProviderInterface::class);
        if (! $provider instanceof LocalMockCardProvider) {
            return self::FAILURE;
        }
        $transaction = $provider->simulatePurchase($card->provider_card_id, $this->argument('request'), $this->argument('amount'));
        // Ephemeral local signer exercises the real verification path; never bypass the verifier.
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $body = json_encode(['cardId' => $card->provider_card_id, 'transactionId' => $transaction], JSON_THROW_ON_ERROR);
        openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
        $originalKey = config('card-provider.photonpay.webhook_public_key');
        $originalQueue = config('queue.default');
        try {
            config(['card-provider.photonpay.webhook_public_key' => openssl_pkey_get_details($key)['key'], 'queue.default' => 'sync']);
            app(ReceiveCardNotificationAction::class)->execute($body, base64_encode($signature), 'issuing', 'auth');
        } finally {
            config(['card-provider.photonpay.webhook_public_key' => $originalKey, 'queue.default' => $originalQueue]);
        }
        $this->info('Local simulation recorded; notification submitted. No real provider request was sent.');

        return self::SUCCESS;
    }
}
