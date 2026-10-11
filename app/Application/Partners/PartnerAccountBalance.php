<?php

namespace App\Application\Partners;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

final class PartnerAccountBalance
{
    public static function calculate(string $advance, string $annualExpense, string $activation, string $annual, string $unclassified, string $reimbursement, string $adjustment, string $actual, bool $hasAdjustments): array
    {
        $decimal = fn ($v) => (string) BigDecimal::of($v)->toScale(8, RoundingMode::HalfUp);
        $advances = BigDecimal::of($advance)->minus($annualExpense);
        $theoretical = $advances->plus($activation)->plus($annual)->plus($unclassified)->minus($reimbursement)->plus($adjustment);

        return ['advances' => $decimal($advances), 'activationCommission' => $decimal($activation), 'annualCommission' => $decimal($annual), 'unclassifiedCommission' => $decimal($unclassified), 'reimbursements' => $decimal($reimbursement), 'adjustments' => $decimal($adjustment), 'hasAdjustments' => $hasAdjustments, 'theoretical' => $decimal($theoretical), 'actual' => $decimal($actual), 'difference' => $decimal($theoretical->minus($actual))];
    }
}
