<?php

namespace App\Domain\Media;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class DirectImageUpload extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = ['kyc_ocr_evidence_encrypted'];

    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'claimed_at' => 'immutable_datetime', 'max_bytes' => 'integer'];
    }
}
