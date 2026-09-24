<?php

namespace App\Console\Commands;

use App\Application\Card\ProcessCardNotificationAction;
use App\Domain\Card\Models\CardProviderEvent;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Infrastructure\Providers\Card\LocalCardSimulation;
use App\Infrastructure\Providers\Card\LocalMockCardProvider;
use App\Infrastructure\Providers\Card\PhotonPayNotificationVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SimulateCardPurchase extends Command
{
    protected $signature = 'cards:mock-purchase {card : Local application card UUID} {amount : Exact USD decimal} {request : Stable UUID; reuse for duplicate delivery}';

    protected $description = 'Simulate an isolated local card purchase and signed local inbox event (never live callbacks)';

    public function handle(): int
    {
        if (! app()->environment('local') || ! LocalCardSimulation::active() || ! Str::isUuid($this->argument('card')) || ! Str::isUuid($this->argument('request'))) {
            $this->error('Requires explicit local Mock, an isolated database and valid card/request UUIDs.');

            return self::FAILURE;
        }
        $card = UserCard::whereKey($this->argument('card'))->firstOrFail();
        $provider = app(CardProviderInterface::class);
        if (! $provider instanceof LocalMockCardProvider || ! str_starts_with($card->provider_card_id, 'MOCK-LOCAL-')) {
            return self::FAILURE;
        }
        $transaction = $provider->simulatePurchase($card->provider_card_id, $this->argument('request'), $this->argument('amount'));
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $body = json_encode(['cardId' => $card->provider_card_id, 'transactionId' => $transaction], JSON_THROW_ON_ERROR);
        openssl_sign($body, $signature, $key, OPENSSL_ALGO_MD5);
        // This isolated synthetic event has a known local resource. It is not a remote callback,
        // does not modify any account key and never relaxes the public webhook account resolver.
        $facts = app(PhotonPayNotificationVerifier::class)->verify($body, base64_encode($signature), 'issuing', 'auth', openssl_pkey_get_details($key)['key']);
        $event = DB::transaction(function () use ($card, $facts): CardProviderEvent {
            UserCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $digest = hash('sha256', 'local-simulation:'.$card->id.':'.$facts['digest']);
            $event = CardProviderEvent::where('event_digest', $digest)->first();
            if (! $event) {
                $event = new CardProviderEvent;
                $event->forceFill(['tenant_id' => $card->tenant_id, 'user_id' => $card->user_id, 'card_id' => $card->id,
                    'card_provider_reference_id' => $card->product->card_provider_reference_id,
                    'event_digest' => $digest, 'category' => 'issuing', 'event_type' => 'auth', 'transaction_id' => $facts['transactionId'], 'status' => 'PENDING'])->save();
            }

            return $event;
        });
        if ($event->status !== 'PROCESSED') {
            app(ProcessCardNotificationAction::class)->execute($event->tenant_id, $event->id);
        }
        $this->info('Local simulation recorded. No real callback or provider request was sent.');

        return self::SUCCESS;
    }
}
