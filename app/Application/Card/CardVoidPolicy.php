<?php

namespace App\Application\Card;

use App\Domain\Admin\Enums\ScopeType;
use App\Domain\Admin\Models\AdminUser;
use App\Domain\Admin\Services\AuthorizationService;
use App\Support\Errors\DomainException;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

final class CardVoidPolicy
{
    public static function allowed(AdminUser $actor): bool
    {
        $auth = app(AuthorizationService::class);
        foreach (['cards.read', 'card_product.manage', 'wallet.read', 'ledger.read'] as $permission) {
            if (! $auth->allows($actor, ScopeType::Platform, null, $permission)) {
                return false;
            }
        }

        return true;
    }

    public static function assertAllowed(string $tenant, string $user, ?string $card = null): void
    {
        if (DB::table('card_void_items')->where('tenant_id', $tenant)->where('user_id', $user)->when($card, fn ($q) => $q->where('card_id', $card))
            ->whereIn('status', ['PENDING', 'RUNNING', 'UNKNOWN'])->exists()) {
            throw new DomainException('CARD_VOID_PENDING', 'This card is being voided. Wait for the operation to finish.', 409);
        }
    }

    public static function operationId(object $item, string $kind): string
    {
        return Uuid::uuid5($item->id, $kind.':'.$item->attempt)->toString();
    }

    public static function assertWorker(string $tenant, string $user, string $card, string $itemId, string $kind, string $request): object
    {
        $item = DB::table('card_void_items as i')->join('card_void_batches as b', 'b.id', '=', 'i.batch_id')
            ->where('i.id', $itemId)->where('i.tenant_id', $tenant)->where('i.user_id', $user)->where('i.card_id', $card)
            ->where('i.status', 'RUNNING')->where('i.lease_until', '>', now())->select('i.*', 'b.actor_id')->first();
        $actor = $item ? AdminUser::find($item->actor_id) : null;
        if (! $item || ! $actor || ! self::allowed($actor) || ! in_array($kind, ['FREEZE', 'RETURN', 'CANCEL'], true)
            || $item->stage !== $kind || $request !== self::operationId($item, $kind)) {
            throw new DomainException('CARD_VOID_PENDING', 'This card is being voided. Wait for the operation to finish.', 409);
        }

        return $item;
    }
}
