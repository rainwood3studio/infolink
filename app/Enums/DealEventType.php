<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Kinds of entries in a deal history.
 */
enum DealEventType: string implements HasColor, HasLabel
{
    case Meeting = 'meeting';
    case ProposalSent = 'proposal_sent';
    case StageChange = 'stage_change';
    case Note = 'note';

    public function getLabel(): string
    {
        return match ($this) {
            self::Meeting => '會議',
            self::ProposalSent => '送出提案',
            self::StageChange => '階段變更',
            self::Note => '備註',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Meeting => 'info',
            self::ProposalSent => 'primary',
            self::StageChange => 'warning',
            self::Note => 'gray',
        };
    }
}
