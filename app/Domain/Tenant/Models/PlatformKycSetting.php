<?php

namespace App\Domain\Tenant\Models;

use App\Domain\Tenant\Enums\KycReviewMode;
use Illuminate\Database\Eloquent\Model;

final class PlatformKycSetting extends Model
{
    public const ID = '00000000-0000-4000-8000-000000000001';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'max_accounts_per_identity' => 'integer', 'review_mode' => KycReviewMode::class];
    }

    public static function current(bool $lock = false): self
    {
        $query = self::query()->whereKey(self::ID);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }

    public function policy(): array
    {
        return ['enabled' => $this->enabled, 'maxAccountsPerIdentity' => $this->max_accounts_per_identity, 'reviewMode' => $this->review_mode->value];
    }
}
