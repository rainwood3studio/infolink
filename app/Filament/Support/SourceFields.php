<?php

namespace App\Filament\Support;

use App\Enums\Source;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;

/**
 * Read-only display of the HasSource traceability columns (source is set automatically, manual by default).
 */
class SourceFields
{
    public static function label(Source|string|null $source): ?string
    {
        $source = is_string($source) ? Source::tryFrom($source) : $source;

        return match ($source) {
            Source::Manual => '手動',
            Source::Redmine => 'Redmine',
            Source::Vault => 'Vault',
            Source::Claude => 'Claude',
            Source::Bank => '銀行',
            Source::System => '系統',
            null => null,
        };
    }

    /**
     * Form section: editable notes plus read-only source / actor, hidden on create.
     */
    public static function section(): Section
    {
        return Section::make('來源')
            ->schema([
                TextEntry::make('source')
                    ->label('來源')
                    ->formatStateUsing(fn (Source|string|null $state): ?string => self::label($state))
                    ->badge()
                    ->color('gray')
                    ->hiddenOn('create'),
                TextEntry::make('actor')
                    ->label('寫入者')
                    ->hiddenOn('create'),
                TextEntry::make('updated_at')
                    ->label('最後更新')
                    ->dateTime()
                    ->hiddenOn('create'),
                Textarea::make('notes')
                    ->label('備註')
                    ->rows(2)
                    ->columnSpanFull(),
            ])
            ->columns(3)
            ->collapsible()
            ->columnSpanFull();
    }

    public static function column(): TextColumn
    {
        return TextColumn::make('source')
            ->label('來源')
            ->formatStateUsing(fn (Source|string|null $state): ?string => self::label($state))
            ->badge()
            ->color('gray')
            ->toggleable(isToggledHiddenByDefault: true);
    }
}
