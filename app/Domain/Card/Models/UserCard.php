<?php

namespace App\Domain\Card\Models;

use App\Domain\CardProduct\Models\CardProduct;
use App\Domain\CardProvider\ProviderReference;
use App\Domain\Ledger\Models\LedgerAccount;
use App\Domain\Ledger\ValueObjects\Money;
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
                'provider', 'provider_card_id', 'card_currency', 'form_factor',
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
            'balance_limit' => 'decimal:8',
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

    public function overflowBalance(): string
    {
        $balance = LedgerAccount::query()->where('tenant_id', $this->tenant_id)
            ->where('user_id', $this->user_id)->where('card_id', $this->id)->where('account_type', 'USER_CARD_OVERFLOW')->value('balance');

        return Money::of($balance ?? '0', 'USD')->amount();
    }

    public function availableBalance(): ?string
    {
        return $this->provider_balance === null ? null : Money::of($this->provider_balance, 'USD')
            ->add(Money::of($this->overflowBalance(), 'USD'))->amount();
    }

    public function effectiveBalanceLimit(): ?string
    {
        return $this->balance_limit ?? $this->product->balance_limit;
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
