<?php

namespace App\Domain\Card\Enums;

enum CardIssueStatus: string
{
    case Processing = 'PROCESSING';
    case Succeeded = 'SUCCEEDED';
    case Failed = 'FAILED';
    case Unknown = 'UNKNOWN';
}
