<?php

namespace App\Domain\Ledger\ValueObjects;

use Brick\Math\BigDecimal;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class AssetAmountCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        if (! isset($attributes['asset_code'])) {
            $fraction = explode('.', (string) $value)[1] ?? '';

            return (string) BigDecimal::of((string) $value)->toScale(max(8, strlen(rtrim($fraction, '0'))));
        }

        return Money::of((string) $value, $attributes['asset_code'])->amount();
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return Money::of($value, $attributes['asset_code'])->amount();
    }
}
