<?php

namespace App\Domain\Promotion\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

final class CompanyInvitation extends Model
{
    use HasUuids;

    protected $table = 'promotion_company_invitations';

    protected $guarded = [];
}
