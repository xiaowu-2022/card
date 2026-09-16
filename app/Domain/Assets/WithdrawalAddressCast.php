<?php

namespace App\Domain\Assets;

use App\Domain\Withdrawal\Services\WithdrawalAddressProtector;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

final class WithdrawalAddressCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): string
    {
        return app(WithdrawalAddressProtector::class)->decrypt($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return app(WithdrawalAddressProtector::class)->encrypt($value);
    }
}
