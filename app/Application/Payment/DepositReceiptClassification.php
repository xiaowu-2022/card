<?php

namespace App\Application\Payment;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Latest append-only correction; original settlement classification stays immutable. */
final class DepositReceiptClassification
{
    public static function current(string $source): Builder
    {
        return DB::table('deposit_receipt_type_changes')->where('source', $source)
            ->selectRaw('DISTINCT ON (source, order_id) source, order_id, tenant_id, after_type, after_journal_id, revision')
            ->orderBy('source')->orderBy('order_id')->orderByDesc('revision');
    }

    public static function effectiveType(string $table): string
    {
        return "COALESCE(receipt_change.after_type, {$table}.manual_receipt_type, 'ACTUAL')";
    }

    public static function join($query, string $table, string $source)
    {
        return $query->leftJoinSub(self::current($source), 'receipt_change', fn ($join) => $join
            ->on('receipt_change.order_id', '=', $table.'.id')->on('receipt_change.tenant_id', '=', $table.'.tenant_id'));
    }
}
