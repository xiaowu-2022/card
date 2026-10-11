<?php

namespace App\Application\Cleanup;

use Illuminate\Support\Facades\DB;

final class CleanupTombstones
{
    public static function contains(string $namespace, string $identifier): bool
    {
        return DB::table('data_cleanup_tombstones')->where('namespace', $namespace)->where('identifier_hash', md5($identifier))->exists();
    }

    public function reserve(string $batch, string $tenant, array $snapshot): void
    {
        $rows = [];
        $add = function (string $namespace, mixed $identifier) use (&$rows, $batch, $tenant): void {
            if ($identifier !== null && $identifier !== '') {
                $hash = md5((string) $identifier);
                $rows[$namespace.'|'.$hash] = ['batch_id' => $batch, 'tenant_id' => $tenant, 'namespace' => $namespace, 'identifier_hash' => $hash, 'created_at' => now()];
            }
        };
        foreach (DB::table('data_cleanup_items')->where('batch_id', $batch)->where('operation', 'DELETE')->select('table_name')->selectRaw('row_key::text AS key')->get() as $item) {
            $add('row:'.$item->table_name, $item->key);
        }
        foreach ($snapshot as $table => $records) {
            foreach ($records as $row) {
                if (isset($row['id'])) {
                    $add('resource', $row['id']);
                }
                if (! empty($row['request_id'])) {
                    $owner = $row['user_id'] ?? $row['sender_user_id'] ?? $row['actor_id'] ?? '';
                    $add('request:'.$table.':'.$tenant.':'.$owner, $row['request_id']);
                }
                if ($table === 'users') {
                    $add('user-account:'.$tenant, $row['account_id']);
                }
                if ($table === 'ledger_entries') {
                    $add('ledger-event:'.$tenant, $row['event_key']);
                }
                if ($table === 'paid_promotion_events') {
                    $add('promotion-event:'.$tenant.':'.$row['kind'], $row['source_id']);
                }
                if ($table === 'wallet_topup_orders' && $row['matched_tx_hash']) {
                    $add('chain:TRON', strtolower($row['matched_tx_hash']).':'.$row['matched_transfer_index']);
                }
                if ($table === 'asset_chain_observations' || in_array($table, ['asset_deposit_orders', 'asset_withdrawal_orders'], true) && ! empty($row['chain_event_id'])) {
                    $add('chain:'.$row['network'], $row['event_id'] ?? $row['chain_event_id']);
                }
                if (in_array($table, ['payment_provider_transactions', 'payment_provider_events'], true)) {
                    foreach (['provider_request_id', 'provider_transaction_id', 'provider_event_key'] as $column) {
                        $add('payment:'.$row['provider'].':'.$column, $row[$column] ?? null);
                    }
                }
                if (in_array($table, ['user_cards', 'provider_cardholders', 'card_issue_orders', 'card_management_orders', 'card_recipient_applications'], true)) {
                    $product = isset($row['card_product_id']) ? DB::table('card_products')->where('id', $row['card_product_id'])->first() : null;
                    if (! $product && isset($row['card_id'])) {
                        $card = collect($snapshot['user_cards'] ?? [])->firstWhere('id', $row['card_id']);
                        $product = $card ? DB::table('card_products')->where('id', $card['card_product_id'])->first() : null;
                    }
                    $providerAccount = $product?->card_provider_reference_id ?? '';
                    foreach (['provider_card_id', 'provider_cardholder_id', 'provider_request_id', 'provider_recipient_id'] as $column) {
                        $add('card:'.$providerAccount.':'.$column, $row[$column] ?? null);
                    }
                    // PhotonPay issue notifications use the internal order UUID.
                    if ($table === 'card_issue_orders') {
                        $add('card:'.$providerAccount.':provider_request_id', $row['id']);
                    }
                }
            }
        }
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            DB::table('data_cleanup_tombstones')->insertOrIgnore($chunk);
        }
    }
}
