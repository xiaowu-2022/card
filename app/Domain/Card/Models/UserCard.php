<?php

namespace App\Domain\Card\Models;

use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\ProviderReference;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class UserCard extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected static function booted(): void
    {
        self::updating(function (self $card): void {
            if ($card->isDirty([
                'tenant_id', 'user_id', 'card_product_id', 'card_issue_order_id', 'provider_cardholder_id',
                'provider', 'provider_card_id', 'card_currency',
            ])) {
                throw new LogicException('User Card identity is immutable.');
            }
        });
        self::deleting(fn () => throw new LogicException('User Cards cannot be deleted.'));
    }

    protected function casts(): array
    {
        return [
            'archived_at' => 'immutable_datetime',
            'refresh_generation' => 'integer',
            'provider_balance' => 'decimal:8',
            'provider_balance_synced_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeWithoutTestReferences(Builder $query): void
    {
        $query->whereRaw('BTRIM(provider_card_id) !~* ?', [ProviderReference::TEST_PATTERN]);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CardProduct::class, 'card_product_id');
    }

    public function issueOrder(): BelongsTo
    {
        return $this->belongsTo(CardIssueOrder::class, 'card_issue_order_id');
    }
}
