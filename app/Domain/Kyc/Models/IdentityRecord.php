<?php

namespace App\Domain\Kyc\Models;

use App\Domain\Kyc\Enums\KycDocumentType;
use App\Domain\Tenant\Models\Tenant;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

final class IdentityRecord extends Model
{
    use HasUuids;

    protected $guarded = ['*'];

    protected $hidden = ['identity_number_encrypted', 'identity_hash'];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('Identity records cannot be updated in Phase 3.'));
        self::deleting(fn () => throw new LogicException('Identity records cannot be deleted in Phase 3.'));
    }

    protected function casts(): array
    {
        return ['document_type' => KycDocumentType::class, 'verified_at' => 'immutable_datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sourceApplication(): BelongsTo
    {
        return $this->belongsTo(KycApplication::class, 'source_kyc_application_id');
    }
}
