<?php

namespace App\Domain\Tenant\Enums;

enum TenantArticleKey: string
{
    case Terms = 'terms';
    case Privacy = 'privacy';
    case AccountClosure = 'account-closure';
}
