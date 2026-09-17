<?php

namespace App\Domain\Assets;

final class CompanyRail extends AssetRecord
{
    protected $table = 'asset_company_rails';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['deposit_enabled' => 'boolean', 'withdrawal_enabled' => 'boolean', 'withdrawal_fee_percent' => 'decimal:8'];
    }
}
