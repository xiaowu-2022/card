<?php

namespace App\Console\Commands;

use App\Application\Card\CardProductProviderRouter;
use App\Application\Card\ManageCardAction;
use App\Application\Card\RefreshManagedCardAction;
use App\Application\Card\UserCardTransactionsQuery;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Models\UserCard;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class TestPhotonPaySandboxManagement extends Command
{
    protected $signature = 'cards:test-photonpay-management {--execute} {--cancel-one}';

    protected $description = 'Run the explicitly authorized sandbox recharge 30 / return 25 acceptance';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::connection()->getDatabaseName() !== 'card_mock') {
            return self::FAILURE;
        }
        $tenant = Tenant::whereKey('01a09996-8c36-7288-bf98-889103088ba6')->sole();
        $user = User::where('tenant_id', $tenant->id)->where('account_id', '202609131303')->sole();
        $cards = UserCard::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)
            ->whereHas('product', fn ($q) => $q->where('card_provider_reference_id', '044a22d6-c487-4604-bc89-1760b9fb79f8'))->orderBy('id')->get();
        if ($cards->count() !== 9) {
            $this->error('Expected exactly nine approved sandbox cards.');

            return self::FAILURE;
        }
        $manage = app(ManageCardAction::class);
        foreach ($cards as $card) {
            try {
                $router = app(CardProductProviderRouter::class);
                if (! $router->isSandbox($card->product)) {
                    throw new \RuntimeException('Sandbox routing required');
                }
                $provider = $router->forCard($card);
                if ($card->provider_status !== 'cancelled') {
                    $sensitive = $provider->revealCard($card->provider_card_id);
                    $cvvValid = preg_match('/^\d{3,4}$/', $sensitive->displayCvv) === 1 && substr($sensitive->displayPan, -4) === $card->last4;
                    unset($sensitive);
                    $this->line(json_encode(['bin' => $card->product->provider_product_ref, 'cvvVerified' => $cvvValid]));
                    if (! $cvvValid) {
                        throw new \RuntimeException('Unconfirmed sensitive response');
                    }
                }
                if ($this->option('execute')) {
                    foreach (['LOAD' => '30.00', 'RETURN' => '25.00'] as $kind => $amount) {
                        $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'photonpay-management-20260914/'.$card->id.'/'.$kind)->toString();
                        $existing = CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('request_id', $key)->first();
                        if ($existing && in_array($existing->status, ['UNKNOWN', 'PROCESSING'], true)) {
                            $order = $manage->sync($tenant->id, $existing->id);
                        } elseif ($kind === 'LOAD') {
                            $order = $manage->quote($tenant->id, $user->id, $card->id, $key, $amount);
                            if ($order->status === 'QUOTED') {
                                $order = $manage->confirmLoad($tenant->id, $user->id, $card->id, $order->id);
                            }
                        } else {
                            $order = $manage->operate($tenant->id, $user->id, $card->id, $key, $kind, $amount);
                        }
                        if (in_array($order->status, ['UNKNOWN', 'PROCESSING'], true)) {
                            $order = $manage->sync($tenant->id, $order->id);
                        }
                        $this->line(json_encode(['bin' => $card->product->provider_product_ref, 'kind' => $kind, 'orderId' => $order->id, 'state' => $order->status, 'debit' => $order->debit_amount, 'arrival' => $order->arrival_amount, 'fee' => $order->fee_amount]));
                        if ($order->status !== 'SUCCEEDED') {
                            return self::FAILURE;
                        }
                    }
                }
                $fresh = app(RefreshManagedCardAction::class)->execute($tenant->id, $user->id, $card->id);
                $transactions = app(UserCardTransactionsQuery::class)->get($tenant->id, $user->id, $card->id, 1);
                $this->line(json_encode(['bin' => $card->product->provider_product_ref, 'balance' => $fresh->provider_balance, 'transactionsRead' => true, 'transactionCount' => count($transactions['items'])]));
            } catch (\Throwable $e) {
                $this->error('Stopped at '.$card->product->provider_product_ref.': '.get_class($e).':'.basename($e->getFile()).':'.$e->getLine());

                return self::FAILURE;
            }
        }
        if ($this->option('cancel-one') && $this->option('execute')) {
            // The user approved exactly one cancellation. Stable selection and request across reruns.
            $card = $cards->firstWhere('provider_card_id', 'XR2099434953904766976');
            $key = Uuid::uuid5(Uuid::NAMESPACE_URL, 'photonpay-management-20260914/'.$card->id.'/CANCEL')->toString();
            $order = $manage->operate($tenant->id, $user->id, $card->id, $key, 'CANCEL');
            if (in_array($order->status, ['UNKNOWN', 'PROCESSING'], true)) {
                $order = $manage->sync($tenant->id, $order->id);
            }
            $this->line(json_encode(['bin' => $card->product->provider_product_ref, 'kind' => 'CANCEL', 'state' => $order->status, 'orderId' => $order->id]));
            if ($order->status === 'SUCCEEDED') {
                $page = app(CardProductProviderRouter::class)->forCard($card)->getTransactionPage($card->provider_card_id, 1, 20);
                foreach ($page->items as $transaction) {
                    if ($transaction->type !== 'transfer_out' || $transaction->state !== 'completed'
                        || CardManagementOrder::query()->where('tenant_id', $tenant->id)->where('card_id', $card->id)->where('provider_transaction_id', $transaction->providerTransactionId)->exists()) {
                        continue;
                    }
                    // The action re-queries exact discard_recharge_return ownership and amounts before posting.
                    $refund = $manage->settleCancellationReturn($tenant->id, $user->id, $card->id, $transaction->providerTransactionId);
                    $this->line(json_encode(['kind' => 'CANCEL_RETURN', 'state' => $refund->status, 'arrival' => $refund->arrival_amount]));
                    if ($refund->status !== 'SUCCEEDED') {
                        return self::FAILURE;
                    }
                }
            }

            return $order->status === 'SUCCEEDED' ? self::SUCCESS : self::FAILURE;
        }

        return self::SUCCESS;
    }
}
