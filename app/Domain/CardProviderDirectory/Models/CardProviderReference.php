<?php

namespace App\Domain\CardProviderDirectory\Models;

use Illuminate\Database\Eloquent\Model;

final class CardProviderReference extends Model
{
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
