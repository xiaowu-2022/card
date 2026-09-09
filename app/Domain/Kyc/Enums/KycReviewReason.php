<?php

namespace App\Domain\Kyc\Enums;

enum KycReviewReason: string
{
    case DocumentUnreadable = 'DOCUMENT_UNREADABLE';
    case DocumentMismatch = 'DOCUMENT_MISMATCH';
    case InformationMismatch = 'INFORMATION_MISMATCH';
    case DocumentExpired = 'DOCUMENT_EXPIRED';
    case Other = 'OTHER';
}
