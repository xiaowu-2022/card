<?php

namespace App\Console\Commands;

use App\Application\Card\ArchiveClearedUserCardAction;
use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\ManageCardAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class ClearAuthorizedSandboxCards extends Command
{
    protected $signature = 'cards:clear-authorized-sandbox {--execute}';

    protected $description = 'Cancel and clear only the ten cards explicitly authorized on 2026-09-14';

    private const IDS = [
        '01a09f50-4072-7112-a2de-be6818bb15de', '01a09f50-b4d7-7162-b05e-bedcfc41c419',
        '01a09f50-ee91-713b-ab7a-eef95aed38ba', '01a09b3f-967a-70c8-b247-eca43d89e31c',
        '01a09f50-ccaf-70db-bbbb-7b9274a5001c', '01a09f50-6be8-7085-bde1-78708274cd38',
        '01a09f50-20c9-718c-8e32-fb006355706e', '01a09f50-2dfc-712f-9b53-55388f2095ae',
        '01a09f50-8da5-7383-8b11-3e89cf334a63', '01a09f4e-d660-7133-ac68-4927be9bc6e1',
    ];

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
            $this->error('Authorized isolated sandbox required.');

            return self::FAILURE;
        }
        $tenant = Tenant::query()->whereKey('01a09996-8c36-7288-bf98-889103088ba6')->sole();
        $user = User::query()->where('tenant_id', $tenant->id)->where('account_id', '202609131303')->sole();
        $cards = UserCard::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereIn('id', self::IDS)->orderBy('id')->get();
        $router = app(CardProductProviderRouter::class);
        if ($cards->count() !== count(self::IDS) || $cards->contains(fn ($card) => ! ($router->isSandbox($card->product) || $router->isLocalMock($card->product)) || ! $router->forCard($card)->available())) {
            $this->error('Card snapshot or provider routing changed.');

            return self::FAILURE;
        }
        if (! $this->option('execute')) {
            $this->line(json_encode(['account' => $user->account_id, 'snapshot_cards' => $cards->count(), 'remaining' => $cards->whereNull('archived_at')->count()]));

            return self::SUCCESS;
        }
        $manage = app(ManageCardAction::class);
        foreach ($cards as $card) {
            if ($card->archived_at !== null) {
                continue;
            }
            $request = Uuid::uuid5(Uuid::NAMESPACE_URL, 'user-confirmed-clear-cards-20260914/'.$card->id)->toString();
            try {
                $intent = AuditLog::query()->where('tenant_id', $tenant->id)->where('actor_id', $user->id)->where('resource_id', $card->id)
                    ->where('action', 'CARD_CLEANUP_REQUESTED')->where('request_id', $request)->first();
                if (! $intent) {
                    $fresh = app(RefreshManagedCardAction::class)->execute($tenant->id, $user->id, $card->id);
                    DB::transaction(function () use ($tenant, $user, $card, $fresh, $request): void {
                        DB::statement('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['card-cleanup:'.$card->id]);
                        if (! AuditLog::query()->where('tenant_id', $tenant->id)->where('resource_id', $card->id)->where('action', 'CARD_CLEANUP_REQUESTED')->where('request_id', $request)->exists()) {
                            app(AuditLogger::class)->record($tenant->id, 'USER', $user->id, 'CARD_CLEANUP_REQUESTED', 'user_card', $card->id, null,
                                ['source' => 'USER_CONFIRMED_SANDBOX_CLEANUP', 'balance_before_cancellation' => $fresh->provider_balance, 'currency' => 'USD', 'provider_status' => $fresh->provider_status], $request);
                        }
                    });
                }
                $order = CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('request_id', $request)->first();
                if ($order && ! $order->terminal()) {
                    $order = $manage->sync($tenant->id, $order->id);
                } elseif (! $order && $card->fresh()->provider_status !== 'cancelled') {
                    $order = $manage->operate($tenant->id, $user->id, $card->id, $request, 'CANCEL');
                }
                if ($order && $order->status !== 'SUCCEEDED') {
                    $this->line(json_encode(['last4' => $card->last4, 'cancellation' => $order->status, 'order_id' => $order->id]));

                    return self::FAILURE;
                }
                // Discover transactions read-only; only the exact cancellation-return query can settle.
                for ($page = 1; $page <= 100; $page++) {
                    $history = $router->forCard($card)->getTransactionPage($card->provider_card_id, $page, 20);
                    foreach ($history->items as $transaction) {
                        if ($transaction->type !== 'transfer_out' || $transaction->state !== 'completed') {
                            continue;
                        }
                        $existing = CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card->id)->where('provider_transaction_id', $transaction->providerTransactionId)->first();
                        if ($existing && $existing->terminal()) {
                            continue;
                        }
                        $refund = $manage->settleCancellationReturn($tenant->id, $user->id, $card->id, $transaction->providerTransactionId);
                        if ($refund->status !== 'SUCCEEDED') {
                            throw new \RuntimeException('Cancellation return remains unconfirmed.');
                        }
                        $this->line(json_encode(['last4' => $card->last4, 'refund' => $refund->status, 'debit' => $refund->debit_amount, 'arrival' => $refund->arrival_amount, 'fee' => $refund->fee_amount]));
                    }
                    if (! $history->hasMore) {
                        break;
                    }
                }
                $fresh = app(RefreshManagedCardAction::class)->execute($tenant->id, $user->id, $card->id);
                app(ArchiveClearedUserCardAction::class)->execute($tenant->id, $user->id, $card->id, $request);
                $this->line(json_encode(['last4' => $card->last4, 'status' => $fresh->provider_status, 'balance' => $fresh->provider_balance, 'archived' => true]));
            } catch (\Throwable $exception) {
                $this->error('Stopped at '.$card->last4.': '.get_class($exception).':'.basename($exception->getFile()).':'.$exception->getLine());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
