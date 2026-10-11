<?php

namespace App\Application\Cleanup;

use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Support\Errors\DomainException;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

final class PlanDataCleanup
{
    public function __construct(private CleanupSchema $schema) {}

    public function build(string $tenant, array $selection): array
    {
        $metadata = $this->schema->metadata();
        $foreignKeys = $this->schema->foreignKeys();
        $data = [];
        foreach (CleanupSchema::TABLES as $table) {
            if (! isset($metadata[$table]['keys'])) {
                throw new DomainException('CLEANUP_SCHEMA_UNSUPPORTED', 'Cleanup schema is incomplete.', 409);
            }
            $query = DB::table($table.' as t')->select('t.*')->selectRaw('md5(to_jsonb(t)::text) AS _cleanup_hash');
            if (in_array('tenant_id', $metadata[$table]['columns'], true)) {
                $query->where('t.tenant_id', $tenant);
            } elseif ($table === 'asset_chain_observations') {
                $query->whereIn('t.order_id', DB::table('asset_deposit_orders')->where('tenant_id', $tenant)->select('id'));
            } else {
                throw new DomainException('CLEANUP_SCHEMA_UNSUPPORTED', 'Cleanup schema is incomplete.', 409);
            }
            foreach ($query->get() as $record) {
                $row = (array) $record;
                $key = $this->key($row, $metadata[$table]['keys']);
                $data[$table][$key] = $row;
            }
            $data[$table] ??= [];
        }
        $users = $selection['user_ids'];
        $modules = $selection['modules'];
        $account = in_array('account', $modules, true);
        if ($account) {
            $modules = ['account', 'orders', 'commissions', 'cards'];
        }
        $subjects = array_values(array_filter($data['users'], fn ($r) => in_array($r['id'], $users, true)));
        if (count($subjects) !== count($users)) {
            throw new DomainException('CLEANUP_USER_SCOPE', 'Choose existing users in this company.', 404);
        }
        $selected = [];
        $roots = [];
        $queue = [];
        $add = function (string $table, string $key, bool $root = false) use (&$selected, &$roots, &$queue, &$data): void {
            if ($root) {
                $roots[$table][$key] = true;
            }
            if (! isset($selected[$table][$key])) {
                $selected[$table][$key] = $table === 'promotion_invitation_reservations' ? 'RETIRE' : 'DELETE';
                $queue[] = [$table, $key];
            }
        };
        $ownedColumns = ['user_id', 'sender_user_id', 'recipient_user_id', 'ancestor_user_id', 'actor_user_id', 'sender_support_user_id'];
        $rootTables = [];
        if (in_array('orders', $modules, true)) {
            $rootTables = [...$rootTables, ...CleanupSchema::ORDERS, 'card_issue_orders', 'card_management_orders'];
        }
        if (in_array('cards', $modules, true)) {
            $rootTables = [...$rootTables, ...CleanupSchema::CARDS];
        }
        if (in_array('commissions', $modules, true)) {
            $rootTables = [...$rootTables, ...CleanupSchema::COMMISSIONS];
        }
        foreach ($data as $table => $rows) {
            foreach ($rows as $key => $row) {
                $owned = $table === 'users' && in_array($row['id'], $users, true);
                foreach ($ownedColumns as $column) {
                    $owned = $owned || isset($row[$column]) && in_array($row[$column], $users, true);
                }
                if ($table === 'consumer_device_tokens') {
                    $owned = in_array($row['tokenable_id'], $users, true);
                }
                if ($table === 'registration_challenges') {
                    $owned = (bool) array_filter($subjects, fn ($u) => in_array($row['destination'], array_filter([$u['email'], $u['phone']]), true));
                }
                if ($owned && ($account || in_array($table, $rootTables, true))) {
                    $add($table, $key, true);
                }
            }
        }
        // Received and source-generated commissions are independent roots. Funding
        // evidence is retained when commissions alone are selected.
        if (in_array('commissions', $modules, true)) {
            $funding = array_column(array_filter($data['promotion_funding_events'], fn ($r) => in_array($r['user_id'], $users, true)), 'id');
            foreach ($data['commission_awards'] as $key => $row) {
                if (in_array($row['funding_event_id'], $funding, true)) {
                    $add('commission_awards', $key, true);
                }
            }
            foreach ($data['paid_promotion_events'] as $key => $row) {
                if (in_array($row['user_id'], $users, true)) {
                    $add('paid_promotion_events', $key, true);
                }
            }
        }
        $blockers = [];
        if ($account) {
            foreach ($data['promotion_members'] as $member) {
                if (in_array($member['user_id'], $users, true) && array_filter($data['promotion_members'], fn ($r) => $r['inviter_id'] === $member['id'])) {
                    $blockers[] = ['code' => 'CLEANUP_HAS_DESCENDANTS', 'accountId' => $this->accountId($data, $member['user_id'])];
                }
            }
        }
        // Build child indexes using actual composite FKs. No broad cascades or
        // guessing ownership from a UUID-shaped string.
        $indexes = [];
        foreach ($foreignKeys as $i => $fk) {
            if (! isset($data[$fk['child']])) {
                continue;
            }
            foreach ($data[$fk['child']] as $key => $row) {
                $value = $this->tuple($row, $fk['columns']);
                if ($value !== null) {
                    $indexes[$i][$value][] = $key;
                }
            }
        }
        for ($position = 0; $position < count($queue); $position++) {
            if (count($queue) > 20000) {
                throw new DomainException('CLEANUP_TOO_LARGE', 'Select fewer accounts for this cleanup.', 422);
            }
            [$table, $key] = $queue[$position];
            $row = $data[$table][$key];
            if ($table === 'promotion_invitation_reservations') {
                continue;
            }
            foreach ($foreignKeys as $i => $fk) {
                if ($fk['parent'] === $table) {
                    $tuple = $this->tuple($row, $fk['parentColumns']);
                    foreach ($indexes[$i][$tuple] ?? [] as $childKey) {
                        // Never expand deletion to another identity.
                        if ($fk['child'] === 'users' && ! in_array($data['users'][$childKey]['id'], $users, true)) {
                            throw new DomainException('CLEANUP_USER_SCOPE', 'Cleanup cannot delete another account.', 409);
                        }
                        $add($fk['child'], $childKey);
                    }
                }
                if ($fk['child'] === $table && ($fk['parent'] === 'ledger_entries'
                    || $fk['parent'] === 'partner_journal_entries' && $table !== 'partner_journal_entries'
                    || $fk['parent'] === 'paid_promotion_cycles' && $table === 'paid_promotion_orders'
                    || $fk['parent'] === 'provider_cardholders' && in_array($table, ['user_cards', 'card_issue_orders'], true))) {
                    $tuple = $this->tuple($row, $fk['columns']);
                    if ($tuple !== null) {
                        foreach ($data[$fk['parent']] as $parentKey => $parent) {
                            if ($this->tuple($parent, $fk['parentColumns']) === $tuple) {
                                $add($fk['parent'], $parentKey);
                            }
                        }
                    }
                }
            }
            if (in_array($table, ['promotion_funding_events', 'paid_promotion_orders', 'manual_promotion_adjustments'], true)) {
                foreach ($data['paid_promotion_events'] as $eventKey => $event) {
                    if ($event['source_id'] === $row['id']) {
                        $add('paid_promotion_events', $eventKey);
                    }
                }
            }
            if (in_array($table, ['paid_promotion_shares', 'commission_awards'], true)) {
                $other = $table === 'paid_promotion_shares' ? 'commission_awards' : 'paid_promotion_shares';
                foreach ($data[$other] as $otherKey => $otherRow) {
                    if ($otherRow['id'] === $row['id']) {
                        $add($other, $otherKey);
                    }
                }
            }
            if (in_array($table, ['wallet_topup_orders', 'asset_deposit_orders'], true)) {
                $source = $table === 'wallet_topup_orders' ? 'primary' : 'asset';
                foreach ($data['deposit_receipt_type_changes'] as $changeKey => $change) {
                    if ($change['source'] === $source && $change['order_id'] === $row['id']) {
                        $add('deposit_receipt_type_changes', $changeKey);
                    }
                }
            }
            if ($table === 'asset_deposit_orders') {
                foreach ($data['asset_chain_observations'] as $observationKey => $observation) {
                    if ($observation['order_id'] === $row['id']) {
                        $add('asset_chain_observations', $observationKey);
                    }
                }
            }
            if ($table === 'user_cards') {
                foreach ($data['ledger_accounts'] as $accountKey => $ledgerAccount) {
                    if ($ledgerAccount['card_id'] === $row['id']) {
                        $add('ledger_accounts', $accountKey);
                    }
                }
                foreach ($data['card_transaction_sync_batches'] as $batchKey => $batch) {
                    if (in_array($row['id'], json_decode($batch['card_ids'], true), true)) {
                        $add('card_transaction_sync_batches', $batchKey);
                    }
                }
            }
            if (isset($row['id'])) {
                foreach ($data['inbox_events'] as $inboxKey => $inbox) {
                    if (in_array($row['id'], explode(':', $inbox['event_key']), true)) {
                        $add('inbox_events', $inboxKey);
                    }
                }
            }
        }
        // Discover non-allowlisted FK dependents, failing closed rather than
        // silently destroying a new module introduced after this feature.
        foreach ($foreignKeys as $fk) {
            if (! isset($selected[$fk['parent']]) || in_array($fk['child'], CleanupSchema::TABLES, true)) {
                continue;
            }
            foreach ($selected[$fk['parent']] as $key => $operation) {
                if ($operation !== 'DELETE') {
                    continue;
                }
                $query = DB::table($fk['child']);
                foreach ($fk['columns'] as $i => $column) {
                    $query->where($column, $data[$fk['parent']][$key][$fk['parentColumns'][$i]]);
                }
                if ($query->exists()) {
                    $blockers[] = ['code' => 'CLEANUP_UNKNOWN_DEPENDENCY', 'table' => $fk['child']];
                }
            }
        }
        $balances = [];
        $affectedUsers = $users;
        $entryIds = array_column(array_intersect_key($data['ledger_entries'], $selected['ledger_entries'] ?? []), 'id');
        $removedDeltas = [];
        foreach ($data['ledger_postings'] as $posting) {
            if (in_array($posting['ledger_entry_id'], $entryIds, true)) {
                $removedDeltas[$posting['ledger_account_id']] = ($removedDeltas[$posting['ledger_account_id']] ?? BigDecimal::zero())->plus($posting['delta']);
            }
        }
        foreach ($data['ledger_accounts'] as $key => $ledgerAccount) {
            if (! isset($removedDeltas[$ledgerAccount['id']]) && ! isset($selected['ledger_accounts'][$key])) {
                continue;
            }
            $after = BigDecimal::of($ledgerAccount['balance'])->minus($removedDeltas[$ledgerAccount['id']] ?? '0');
            if ($after->isNegative() && ! LedgerAccountType::from($ledgerAccount['account_type'])->permitsNegativeBalance()) {
                $blockers[] = ['code' => 'CLEANUP_NEGATIVE_BALANCE', 'accountId' => $this->accountId($data, $ledgerAccount['user_id']), 'asset' => $ledgerAccount['asset_code'], 'accountType' => $ledgerAccount['account_type']];
            }
            if ($ledgerAccount['user_id']) {
                $affectedUsers[] = $ledgerAccount['user_id'];
            }
            $balances[] = ['id' => $ledgerAccount['id'], 'userId' => $ledgerAccount['user_id'], 'accountId' => $this->accountId($data, $ledgerAccount['user_id']),
                'asset' => $ledgerAccount['asset_code'], 'accountType' => $ledgerAccount['account_type'],
                'before' => $ledgerAccount['balance'], 'after' => (string) $after, 'removed' => isset($selected['ledger_accounts'][$key])];
        }
        $items = [];
        $counts = [];
        $images = [];
        $snapshot = [];
        foreach ($selected as $table => $keys) {
            foreach ($keys as $key => $operation) {
                $row = $data[$table][$key];
                $items[] = ['table_name' => $table, 'row_key' => $key, 'row_hash' => $row['_cleanup_hash'], 'operation' => $operation];
                $counts[$table] ??= ['table' => $table, 'count' => 0, 'related' => 0, 'operation' => $operation];
                $counts[$table]['count']++;
                if (! isset($roots[$table][$key])) {
                    $counts[$table]['related']++;
                }
                foreach (['user_id', 'sender_user_id', 'recipient_user_id', 'ancestor_user_id', 'sender_support_user_id'] as $column) {
                    if (! empty($row[$column])) {
                        $affectedUsers[] = $row[$column];
                    }
                }
                foreach (['front_object_key', 'back_object_key', 'image_object_key'] as $column) {
                    if (! empty($row[$column])) {
                        $images[$row[$column]] = ['disk' => 'private', 'key' => $row[$column]];
                    }
                }
                if ($table === 'direct_image_uploads') {
                    foreach (array_filter([$row['staging_image_id'], $row['image_id']]) as $imageId) {
                        $image = DB::table('stored_images')->where('tenant_id', $tenant)->where('id', $imageId)->first();
                        if ($image && $image->source_disk === 'private') {
                            $images[$image->source_key] = ['disk' => 'private', 'key' => $image->source_key];
                        }
                    }
                }
                unset($row['_cleanup_hash']);
                $snapshot[$table][] = $row;
            }
        }
        usort($items, fn ($a, $b) => strcmp($a['table_name'].$a['row_key'], $b['table_name'].$b['row_key']));
        ksort($counts);
        usort($balances, fn ($a, $b) => strcmp($a['id'], $b['id']));
        $affected = array_values(array_filter($data['users'], fn ($u) => in_array($u['id'], $affectedUsers, true)));
        $summary = ['counts' => array_values($counts), 'total' => count($items), 'balances' => $balances, 'blockers' => $blockers,
            'affectedAccounts' => array_map(fn ($u) => ['id' => $u['id'], 'accountId' => $u['account_id'], 'deleted' => $account && in_array($u['id'], $users, true)], $affected)];
        return ['items' => $items, 'summary' => $summary, 'snapshot' => $snapshot, 'images' => array_values($images),
            'fingerprint' => hash('sha256', json_encode([$items, $summary, array_values($images)], JSON_THROW_ON_ERROR))];
    }

    private function key(array $row, array $columns): string
    {
        $key = array_intersect_key($row, array_flip($columns));
        ksort($key);
        return json_encode($key, JSON_THROW_ON_ERROR);
    }

    private function tuple(array $row, array $columns): ?string
    {
        $values = array_map(fn ($c) => $row[$c], $columns);
        return in_array(null, $values, true) ? null : json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function accountId(array $data, ?string $user): ?string
    {
        foreach ($data['users'] as $row) {
            if ($row['id'] === $user) {
                return $row['account_id'];
            }
        }
        return null;
    }
}
