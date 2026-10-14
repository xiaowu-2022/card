<?php

namespace App\Application\Card;

use App\Domain\Card\Models\CardIssueOrder;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\CardProviderDirectory\Models\CardProviderReference;
use App\Infrastructure\Assets\ExactJson;
use App\Infrastructure\Providers\Card\U1CardProvider;
use App\Jobs\ProcessU1Notification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class U1Callbacks
{
    public function dispatchMatching(string $connectionId, string $apiId): void
    {
        foreach (DB::table('u1_notifications')->where('connection_id', $connectionId)->where('api_id', $apiId)
            ->where('verified', true)->whereIn('processing_status', ['PENDING', 'UNMAPPED', 'RETRY'])->pluck('id') as $id) {
            ProcessU1Notification::dispatch($connectionId, $id)->afterCommit();
        }
    }

    public function process(string $connectionId, string $notificationId): string
    {
        $event = DB::transaction(function () use ($connectionId, $notificationId) {
            $row = DB::table('u1_notifications')->where('connection_id', $connectionId)->where('id', $notificationId)->lockForUpdate()->firstOrFail();
            if (! $row->verified || $row->processing_status === 'PROCESSED'
                || ($row->processing_status === 'PROCESSING' && $row->processing_started_at && CarbonImmutable::parse($row->processing_started_at)->addSeconds(60)->isFuture())) {
                return null;
            }
            DB::table('u1_notifications')->where('id', $row->id)->update(['processing_status' => 'PROCESSING', 'processing_started_at' => now(), 'processing_attempts' => $row->processing_attempts + 1]);

            return $row;
        });
        if (! $event) {
            return 'SKIPPED';
        }
        $failure = null;
        $status = 'UNMAPPED';
        try {
            $connection = DB::table('u1_connections')->where('id', $connectionId)->firstOrFail();
            CardProviderReference::includingDeleted()->whereKey($connection->reference_id)->firstOrFail();
            $products = DB::table('card_products')->where('card_provider_reference_id', $connection->reference_id)->select('id');
            $data = ExactJson::decode(Crypt::decryptString($event->body_encrypted));
            if ($event->api_id) {
                $intent = DB::table('u1_operations')->where('connection_id', $connectionId)->where('api_id', $event->api_id)->first();
                if ($intent && $intent->kind === 'OPEN') {
                    $order = CardIssueOrder::where('provider', 'U1')->whereIn('card_product_id', $products)->where('provider_request_id', $intent->request_id)->first();
                    if ($order) {
                        $result = app(SyncCardIssueAction::class)->execute($order->tenant_id, $order->id, $order->user_id);
                        $status = in_array($result->status->value, ['SUCCEEDED', 'FAILED'], true) ? 'PROCESSED' : 'RETRY';
                    }
                } elseif ($intent) {
                    $order = CardManagementOrder::where('provider_request_id', $intent->request_id)->whereIn('card_id', UserCard::where('provider', 'U1')->whereIn('card_product_id', $products)->select('id'))->first();
                    if ($order) {
                        $result = app(ManageCardAction::class)->sync($order->tenant_id, $order->id);
                        $status = $result->terminal() ? 'PROCESSED' : 'RETRY';
                    }
                }
            } else {
                $cardNo = $data['cardNo'] ?? $data['cardno'] ?? null;
                $card = is_string($cardNo) ? UserCard::where('provider', 'U1')->whereIn('card_product_id', $products)->where('provider_card_id', $cardNo)->first() : null;
                if ($card) {
                    if ($event->notification_type === 'otp') {
                        // The signed OTP stays encrypted and is read only by the live card owner.
                        $status = 'PROCESSED';
                    } else {
                        app(RefreshManagedCardAction::class)->execute($card->tenant_id, $card->user_id, $card->id);
                        $status = $event->notification_type === 'transaction' && ! $this->syncTransactions($card, new U1CardProvider($connection)) ? 'RETRY' : 'PROCESSED';
                    }
                }
            }
        } catch (\Throwable $error) {
            $status = 'RETRY';
            $failure = $error;
        }
        DB::table('u1_notifications')->where('id', $notificationId)->where('connection_id', $connectionId)->where('processing_attempts', $event->processing_attempts + 1)
            ->update(['processing_status' => $status, 'processed_at' => $status === 'PROCESSED' ? now() : null]);
        if ($status === 'RETRY') {
            Log::channel('u1_notifications')->warning('U1 notification processing deferred', [
                'connection_id' => $connectionId, 'notification_id' => $notificationId,
                'notification_type' => $event->notification_type, 'attempt' => $event->processing_attempts + 1,
                'reason' => $failure ? 'PROCESSING_EXCEPTION' : 'PROVIDER_EVIDENCE_PENDING',
                'exception_class' => $failure ? get_class($failure) : null,
            ]);
            if ($failure && ! $failure instanceof \App\Domain\CardProvider\Exceptions\ProviderUnknownResultException
                && ! $failure instanceof \App\Domain\CardProvider\Exceptions\ProviderUnavailableException) {
                throw new \RuntimeException('U1 notification processing failed: '.get_class($failure));
            }
        }

        return $status;
    }

    private function syncTransactions(UserCard $card, U1CardProvider $provider): bool
    {
        DB::table('u1_transaction_positions')->insertOrIgnore(['card_id' => $card->id, 'next_offset' => 0, 'updated_at' => now()]);
        $offset = (int) DB::table('u1_transaction_positions')->where('card_id', $card->id)->value('next_offset');
        $page = max(1, intdiv(max(0, $offset - 100), 100) + 1);
        for ($batch = 0; $batch < 5; $batch++, $page++) {
            $started = CarbonImmutable::now();
            $result = $provider->getTransactionPage($card->provider_card_id, $page, 100);
            app(RecordCardTransactionsAction::class)->execute($card, $result->items, $started);
            $next = ($page - 1) * 100 + count($result->items);
            DB::table('u1_transaction_positions')->where('card_id', $card->id)->where('next_offset', '<=', $next)
                ->update(['next_offset' => $next, 'updated_at' => now()]);
            if (! $result->hasMore) {
                return true;
            }
        }

        return false;
    }

    public function otp(string $tenantId, string $userId, string $cardId): ?array
    {
        $card = app(CardManagementAccess::class)->card($tenantId, $userId, $cardId, requireProvider: false);
        if ($card->provider !== 'U1') {
            return null;
        }
        $connection = DB::table('u1_connections')->where('reference_id', $card->product->card_provider_reference_id)->first();
        if (! $connection || ! CardProviderReference::whereKey($connection->reference_id)->exists()) {
            return null;
        }
        $hash = hash('sha256', $card->provider_card_id);
        $events = DB::table('u1_notifications')->where('connection_id', $connection->id)->where('notification_type', 'otp')->where('verified', true)
            ->where('resource_hash', $hash)->where('received_at', '>=', now()->subMinutes(5))->orderByDesc('received_at')->limit(10)->get();
        foreach ($events as $event) {
            $data = ExactJson::decode(Crypt::decryptString($event->body_encrypted));
            $time = CarbonImmutable::createFromTimestampMs($data['timestamp'], 'UTC');
            if (($data['cardNo'] ?? null) === $card->provider_card_id && preg_match('/^\d{4,8}$/D', $data['otp'] ?? '')
                && $time->greaterThanOrEqualTo(now()->subMinutes(5)) && $time->lessThanOrEqualTo(now()->addSeconds(30))) {
                return ['code' => $data['otp'], 'expiresAt' => $time->addMinutes(5)->toIso8601String()];
            }
        }

        return null;
    }
}
