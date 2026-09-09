<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Tenant\Enums\KycReviewMode;
use Illuminate\Database\Eloquent\Model;

final class TenantKycSetting extends Model
{
    protected $primaryKey = 'tenant_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'review_mode' => KycReviewMode::class];
    }
}
