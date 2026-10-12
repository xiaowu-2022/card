<?php

namespace App\Domain\CardProviderDirectory\Models;

use App\Domain\Card\Models\UserCard;
use App\Domain\CardProduct\Models\CardProduct;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class CardProviderReference extends Model
{
    protected static function booted(): void
    {
        // Deletion audit is the permanent tombstone; retain the FK identity and private
        // routing configuration for historical orders and late verified results.
        self::addGlobalScope('deleted_reference', fn (Builder $query) => $query->whereNotExists(fn ($audit) => $audit->selectRaw('1')->from('audit_logs')
            ->where('action', 'CARD_PROVIDER_REFERENCE_DELETED')->where('resource_type', 'card_provider_reference')
            ->whereColumn('resource_id', 'platform_card_provider_references.id')));
    }

    public function scopeIncludingDeleted(Builder $query): void
    {
        $query->withoutGlobalScope('deleted_reference');
    }

    public static function currentCards(string $id): Builder
    {
        return UserCard::query()->whereIn('card_product_id', CardProduct::query()->where('card_provider_reference_id', $id)->select('id'))
            ->whereNull('archived_at')->where(fn ($q) => $q->whereNull('provider_status')->orWhere('provider_status', '<>', 'cancelled'));
    }

    protected $table = 'platform_card_provider_references';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['*'];

    protected $hidden = ['photonpay_reporting_encrypted', 'photonpay_issuing_encrypted', 'photonpay_webhook_key_encrypted'];

    protected function casts(): array
    {
        return ['photonpay_identity' => 'array', 'photonpay_enabled' => 'boolean', 'photonpay_checked_at' => 'immutable_datetime', 'bin_catalog' => 'array', 'reference_balance' => 'decimal:8', 'version' => 'integer'];
    }
}
