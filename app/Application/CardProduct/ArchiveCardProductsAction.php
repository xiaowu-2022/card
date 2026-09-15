<?php

namespace App\Application\CardProduct;

use App\Domain\Admin\Enums\AdminUserStatus;
use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Domain\Audit\Services\AuditLogger;
use App\Domain\CardProduct\Models\CardProduct;
use Illuminate\Support\Facades\DB;

final class ArchiveCardProductsAction
{
    /** @param list<string> $ids Explicit snapshot, never products created after the request. */
    public function execute(array $ids, AdminUser $actor): int
    {
        $actor = $actor->fresh();
        abort_unless($actor && $actor->status === AdminUserStatus::Active
            && app(AuthorizationService::class)->allows($actor, ScopeType::Platform, null, 'card_product.manage'), 403);

        return DB::transaction(function () use ($ids, $actor): int {
            $products = CardProduct::query()->whereIn('id', $ids)->whereNull('archived_at')->orderBy('id')->lockForUpdate()->get();
            foreach ($products as $product) {
                $before = ['status' => $product->status->value, 'archived_at' => null];
                $product->forceFill(['status' => 'INACTIVE', 'archived_at' => now()])->save();
                app(AuditLogger::class)->record(null, 'ADMIN', $actor->id, 'CARD_PRODUCT_ARCHIVED', 'card_product', $product->id, $before,
                    ['status' => 'INACTIVE', 'archived_at' => $product->archived_at->toIso8601String()]);
            }

            return $products->count();
        });
    }
}
