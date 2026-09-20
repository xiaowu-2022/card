<?php

namespace App\Domain\Ledger\Enums;

enum LedgerAccountType: string
{
    case UserCardOverflow = 'USER_CARD_OVERFLOW';
    case UserWealthPrincipal = 'USER_WEALTH_PRINCIPAL';
    case TenantWealthInterestClearing = 'TENANT_WEALTH_INTEREST_CLEARING';
    case UserAvailable = 'USER_AVAILABLE';
    case UserSecurityDeposit = 'USER_SECURITY_DEPOSIT';
    case UserWithdrawalHold = 'USER_WITHDRAWAL_HOLD';
    case UserCardIssueHold = 'USER_CARD_ISSUE_HOLD';
    case UserCardFundingHold = 'USER_CARD_FUNDING_HOLD';
    case UserCommission = 'USER_COMMISSION';
    case TenantExchangeClearing = 'TENANT_EXCHANGE_CLEARING';
    case TenantTopupClearing = 'TENANT_TOPUP_CLEARING';
    case TenantWithdrawalClearing = 'TENANT_WITHDRAWAL_CLEARING';
    case TenantCardFundingClearing = 'TENANT_CARD_FUNDING_CLEARING';
    case TenantPromotionFeeRevenue = 'TENANT_PROMOTION_FEE_REVENUE';
    case TenantFeeRevenue = 'TENANT_FEE_REVENUE';
    case TenantCommissionClearing = 'TENANT_COMMISSION_CLEARING';

    /** @return list<self> */
    public static function userTypes(): array
    {
        return [self::UserAvailable, self::UserSecurityDeposit, self::UserWithdrawalHold, self::UserCardIssueHold, self::UserCardFundingHold];
    }

    /** @return list<self> */
    public static function tenantTypes(): array
    {
        return [self::TenantTopupClearing, self::TenantWithdrawalClearing, self::TenantCardFundingClearing, self::TenantFeeRevenue];
    }

    public function permitsNegativeBalance(): bool
    {
        return in_array($this, [self::TenantWealthInterestClearing, self::TenantTopupClearing, self::TenantWithdrawalClearing, self::TenantCardFundingClearing, self::TenantCommissionClearing, self::TenantExchangeClearing], true);
    }
}
