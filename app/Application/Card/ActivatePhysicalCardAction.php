<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProvider\Contracts\PhysicalCardProviderInterface;
use App\Domain\CardProvider\Exceptions\ProviderRejectedException;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ActivatePhysicalCardAction
{
    public function execute(string $tenantId, string $userId, string $cardId, string $requestId, string $expiry,
        #[\SensitiveParameter] string $pin, #[\SensitiveParameter] string $confirmation): string
    {
        RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId);
        $access = app(CardManagementAccess::class);
        $card = $access->card($tenantId, $userId, $cardId);
        if ($card->form_factor !== 'physical_card') {
            throw new DomainException('CARD_FORM_INVALID', 'Only physical cards can be activated.', 409);
        }
        $fresh = app(RefreshManagedCardAction::class)->execute($tenantId, $userId, $cardId);
        // An outstanding attempt can only be queried, never resent with the same or another PIN.
        $attempt = DB::transaction(function () use ($access, $tenantId, $userId, $cardId, $requestId, $fresh): array {
            $card = $access->card($tenantId, $userId, $cardId, lock: true);
            RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId);
            $query = DB::table('card_activation_attempts')->where('card_id', $cardId);
            $existing = (clone $query)->where('request_id', $requestId)->first();
            $pending = (clone $query)->whereIn('status', ['PROCESSING', 'UNKNOWN', 'SUCCEEDED'])->first();
            if ($existing || $pending) {
                return ['id' => ($existing ?? $pending)->id, 'send' => false];
            }
            if ($card->provider_status !== 'unactivated' || $fresh->provider_status !== 'unactivated' || $card->refresh_generation !== $fresh->refresh_generation) {
                throw new DomainException('CARD_ACTIVATION_UNAVAILABLE', 'This card is not awaiting activation.', 409);
            }
            if (DB::table('card_management_orders')->where('card_id', $cardId)->whereIn('status', ['QUOTING', 'QUOTED', 'PROCESSING', 'UNKNOWN'])->exists()) {
                throw new DomainException('CARD_OPERATION_PENDING', 'Complete the current card operation first.', 409);
            }
            $id = (string) Str::uuid();
            DB::table('card_activation_attempts')->insert(['id' => $id, 'card_id' => $cardId, 'tenant_id' => $tenantId, 'user_id' => $userId,
                'request_id' => $requestId, 'status' => 'PROCESSING', 'created_at' => now(), 'updated_at' => now()]);
            app(AuditLogger::class)->record($tenantId, 'USER', $userId, 'CARD_ACTIVATION_REQUESTED', 'user_card', $cardId);

            return ['id' => $id, 'send' => true];
        });
        if ($attempt['send']) {
            $provider = app(CardProductProviderRouter::class)->forCard($card);
            try {
                if (! $provider instanceof PhysicalCardProviderInterface) {
                    throw new ProviderRejectedException('Physical cards unavailable.');
                }
                $provider->activatePhysicalCard($card->provider_card_id, $expiry, $pin, $confirmation);
                $status = 'UNKNOWN'; // Acknowledgement is not activation completion.
            } catch (ProviderRejectedException) {
                $status = 'FAILED';
            } catch (\Throwable) {
                $status = 'UNKNOWN';
            }
            DB::transaction(function () use ($attempt, $tenantId, $userId, $status) {
                $changed = DB::table('card_activation_attempts')->where('tenant_id', $tenantId)->where('id', $attempt['id'])->where('status', 'PROCESSING')->update(['status' => $status, 'updated_at' => now()]);
                if ($changed && $status === 'FAILED') {
                    app(\App\Application\Inbox\InboxWriter::class)->record($tenantId, $userId, 'card_activation_failed:'.$attempt['id'], 'card_activation_failed', [], '/cards');
                }
            });
            app(AuditLogger::class)->record($tenantId, 'USER', $userId, 'CARD_ACTIVATION_RETURNED', 'user_card', $cardId, null, ['status' => $status]);
            try {
                app(RefreshManagedCardAction::class)->execute($tenantId, $userId, $cardId);
            } catch (\Throwable) { /* Preserve UNKNOWN. */
            }
        }

        return DB::table('card_activation_attempts')->where('id', $attempt['id'])->value('status');
    }
}
