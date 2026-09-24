<?php

namespace App\Application\Card;

use App\Domain\Audit\Services\AuditLogger;
use App\Domain\Card\Models\CardManagementOrder;
use App\Domain\Card\Services\CardholderGeography;
use App\Domain\CardProvider\Contracts\CardProviderInterface;
use App\Domain\Ledger\ValueObjects\Money;
use App\Domain\SecurityDeposit\Services\RefundCardPolicy;
use App\Support\Errors\DomainException;
use App\Support\Logging\PhotonPayLog;

final readonly class UserCardManagementAction
{
    public function __construct(private CardManagementAccess $access, private ManageCardAction $manage,
        private RefreshManagedCardAction $refresh, private CardProviderInterface $provider, private AuditLogger $audit,
        private CardholderGeography $geography, private UserCardholderDetailsQuery $holderDetails) {}

    /** Receives only FormRequest-validated, allowlisted values. Passwords never enter this action. */
    public function execute(string $tenantId, string $userId, string $cardId, DTOs\CardManagementInput $request): array
    {
        return PhotonPayLog::run('management', ['tenant_id' => $tenantId, 'resource_id' => $cardId, 'card_action' => $request->fields()['action']], function () use ($tenantId, $userId, $cardId, $request): array {
            $input = $request->fields();
            RefundCardPolicy::assertAllowed($tenantId, $userId, $cardId);
            $card = $this->access->card($tenantId, $userId, $cardId, requireProvider: $input['action'] !== 'history');
            $action = $input['action'];
            if ($action === 'holder_details') {
                return ['fields' => $this->holderDetails->execute($tenantId, $userId, $cardId)];
            }
            if ($action === 'history') {
                return ['orders' => CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_id', $cardId)->orderByRaw("CASE WHEN status IN ('PROCESSING','UNKNOWN','QUOTING') THEN 0 ELSE 1 END")->latest('created_at')->limit(30)->get()->map(self::order(...))->all()];
            }
            if (in_array($action, ['sync', 'confirm'], true)) {
                $order = CardManagementOrder::query()->where('tenant_id', $tenantId)->where('user_id', $userId)->where('card_id', $cardId)->whereKey($input['order_id'])->firstOrFail();

                return self::order($action === 'confirm' ? $this->manage->confirmLoad($tenantId, $userId, $cardId, $order->id) : $this->manage->sync($tenantId, $order->id));
            }
            if ($action === 'reveal') {
                if (! in_array($card->provider_status, ['normal', 'frozen'], true)) {
                    throw new DomainException('CARD_STATUS_UNAVAILABLE', 'This operation is not available for the current card status.', 409);
                }
                try {
                    $sensitive = app(CardProductProviderRouter::class)->forCard($card)->revealCard($card->provider_card_id);
                } catch (\Throwable) {
                    throw new DomainException('CARD_REVEAL_UNAVAILABLE', 'Card details could not be loaded. Please try again later.', 503);
                }
                if (! preg_match('/^[0-9]{12,19}$/', $sensitive->displayPan) || substr($sensitive->displayPan, -4) !== $card->last4 || ! preg_match('/^[0-9]{3,4}$/', $sensitive->displayCvv)) {
                    throw new DomainException('CARD_REVEAL_UNAVAILABLE', 'Card details could not be loaded. Please try again later.', 503);
                }
                $this->audit->record($tenantId, 'USER', $userId, 'CARD_CVV_VIEWED', 'user_card', $cardId);

                // Explicit transient allowlist only; never persist or serialize the provider DTO.
                return ['pan' => $sensitive->displayPan, 'cvv' => $sensitive->displayCvv];
            }
            if ($action === 'refresh') {
                try {
                    $fresh = $this->refresh->execute($tenantId, $userId, $cardId);
                } catch (\Throwable) {
                    throw new DomainException('CARD_REFRESH_UNAVAILABLE', 'The latest card information could not be confirmed.', 503);
                }

                return ['balance' => $fresh->availableBalance(), 'syncedAt' => $fresh->provider_balance_synced_at?->toIso8601String()];
            }
            if ($action === 'quote') {
                return self::order($this->manage->quote($tenantId, $userId, $cardId, $input['request_id'], $input['amount']));
            }
            $fields = [];
            if ($action === 'holder') {
                foreach (['email' => 'email', 'date_of_birth' => 'dateOfBirth', 'nationality_country_code' => 'nationalityCountryCode',
                    'residential_country_code' => 'residentialCountryCode', 'residential_state' => 'residentialState', 'residential_city' => 'residentialCity',
                    'residential_address' => 'residentialAddress', 'residential_postal_code' => 'residentialPostalCode'] as $source => $target) {
                    if (isset($input[$source])) {
                        $fields[$target] = $input[$source];
                    }
                }
                if (isset($input['nationality_country_code'])) {
                    $fields['certCountryCode'] = $input['nationality_country_code'];
                }
                if (isset($input['mobile'])) {
                    $phone = $this->geography->phone($input['mobile'], $input['mobile_country_code']);
                    $fields['mobile'] = $phone['mobile'];
                    $fields['mobilePrefix'] = $phone['mobile_prefix'];
                }
                if ($fields === []) {
                    throw new DomainException('CARD_HOLDER_EMPTY', 'Enter at least one change.');
                }
            }

            return self::order($this->manage->operate($tenantId, $userId, $cardId, $input['request_id'],
                $action === 'holder' ? 'HOLDER_UPDATE' : strtoupper($action), $input['amount'] ?? null, $fields));
        });
    }

    public static function order(CardManagementOrder $order): array
    {
        return ['id' => $order->id, 'kind' => strtolower($order->kind),
            'state' => match ($order->status) {
                'QUOTED' => 'quoted','SUCCEEDED' => 'completed','FAILED' => 'declined','EXPIRED' => 'expired',default => 'confirming'
            },
            'amount' => $order->kind === 'LOAD' ? Money::of($order->amount, 'USD')->add(Money::of($order->manual_funding_amount, 'USD'))->amount() : $order->amount, 'debit' => $order->debit_amount, 'arrival' => $order->arrival_amount === null ? null : Money::of($order->arrival_amount, 'USD')->add(Money::of($order->kind === 'LOAD' ? $order->manual_funding_amount : '0', 'USD'))->amount(), 'fee' => $order->fee_amount,
            'expiresAt' => $order->quote_expires_at?->toIso8601String(), 'createdAt' => $order->created_at->toIso8601String()];
    }
}
