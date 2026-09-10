<?php

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserProfile extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $hidden = [
        'legal_first_name', 'legal_last_name', 'date_of_birth', 'nationality_country_code',
        'residential_address', 'residential_city', 'residential_state', 'residential_country_code', 'residential_postal_code',
    ];

    protected function casts(): array
    {
        return ['date_of_birth' => 'immutable_date'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
