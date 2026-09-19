<?php

namespace App\Domain\Tenant\Models;

use Illuminate\Database\Eloquent\Model;

final class TenantBusinessSetting extends Model
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'required_security_deposit_amount' => 'decimal:8',
            'security_deposit_refund_wait_days' => 'integer',
            'withdrawal_fee_percent' => 'decimal:8',
            'tron_minimum_deposit' => 'decimal:8',
            'allow_wallet_topup' => 'boolean',
            'allow_withdrawal' => 'boolean',
        ];
    }
}
