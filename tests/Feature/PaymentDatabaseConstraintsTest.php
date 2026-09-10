<?php

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

beforeEach(fn () => $this->seed());

it('retains Phase 5 settlement and Phase 7 Withdrawal beside the Phase 8 shared-address fields', function (): void {
    expect(Schema::hasTable('wallet_topup_orders'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_transactions'))->toBeTrue()
        ->and(Schema::hasTable('payment_provider_events'))->toBeTrue()
        ->and(Schema::hasTable('withdrawal_orders'))->toBeTrue()
        ->and(Schema::hasTable('topup_suffix_slots'))->toBeFalse()
        ->and(Schema::hasTable('topup_amount_reservations'))->toBeFalse()
        ->and(Schema::hasTable('security_deposit_refund_requests'))->toBeFalse()
        ->and(Schema::hasTable('user_cards'))->toBeFalse();
    $constraints = DB::table('pg_constraint')->whereIn('conname', [
        'topup_status_check', 'topup_asset_check', 'topup_amount_check', 'topup_wallet_owner_fk',
        'payment_transaction_order_fk', 'payment_event_transaction_fk', 'payment_event_processing_check',
        'topup_financial_state_check', 'topup_trc20_identity_check', 'topup_trc20_match_check',
        'topup_trc20_detection_state_check', 'topup_trc20_paid_check', 'topup_trc20_expired_check',
    ])->pluck('conname');
    expect($constraints)->toHaveCount(13)
        ->and(Schema::hasColumns('wallet_topup_orders', [
            'requested_amount', 'expected_amount', 'identification_increment', 'network_code', 'deposit_address',
            'expires_at', 'matched_tx_hash', 'matched_transfer_index', 'blockchain_detected_at', 'blockchain_confirmed_at',
        ]))->toBeTrue()
        ->and(Schema::hasColumns('payment_provider_transactions', ['initiation_attempted_at', 'initiation_lease_expires_at']))->toBeTrue();
});

it('rejects invalid direct payment amounts and states at the database boundary', function (): void {
    $tenantId = DB::table('tenants')->value('id');
    expect(fn () => DB::table('wallet_topup_orders')->insert([
        'id' => fake()->uuid(), 'tenant_id' => $tenantId, 'user_id' => fake()->uuid(), 'wallet_id' => fake()->uuid(),
        'request_id' => fake()->uuid(), 'request_hash' => str_repeat('a', 64), 'asset_code' => 'USD',
        'amount' => '0.00000000', 'status' => 'MANUAL_PAID', 'payment_provider' => 'mock', 'created_at' => now(), 'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('exposes no manual settlement or generic balance mutation routes', function (): void {
    $uris = collect(Route::getRoutes())->pluck('uri')->map(fn (string $uri): string => strtolower($uri));
    foreach (['mark-paid', 'mark-credited', 'credit-wallet', 'adjust-balance', 'manual-topup', 'force-success'] as $forbidden) {
        expect($uris->contains(fn (string $uri): bool => str_contains($uri, $forbidden)))->toBeFalse();
    }
});
