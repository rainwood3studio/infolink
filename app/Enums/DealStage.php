<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

/**
 * Sales pipeline stage; won and lost are closed.
 */
enum DealStage: string implements HasColor, HasLabel
{
    case Lead = 'lead';
    case Proposal = 'proposal';
    case Negotiation = 'negotiation';
    case Won = 'won';
    case Lost = 'lost';

    public function getLabel(): string
    {
        return match ($this) {
            self::Lead => '潛在',
            self::Proposal => '提案',
            self::Negotiation => '議價',
            self::Won => '成交',
            self::Lost => '未成交',
        };
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Lead => 'gray',
            self::Proposal => 'info',
            self::Negotiation => 'warning',
            self::Won => 'success',
            self::Lost => 'danger',
        };
    }

    public function isClosed(): bool
    {
        return in_array($this, [self::Won, self::Lost], true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Lead, self::Proposal, self::Negotiation];
    }
}
