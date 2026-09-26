<?php

namespace App\Filament\Resources\Reports\Schemas;

use App\Filament\Resources\Reports\Tables\ReportsTable;
use App\Filament\Support\SourceFields;
use App\Models\Report;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ReportInfolist
{
    public const string OBSIDIAN_VAULT = '2ndBrain';

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make()
                    ->schema([
                        TextEntry::make('title')
                            ->label('標題')
                            ->size('lg')
                            ->weight('bold')
                            ->columnSpanFull(),
                        TextEntry::make('type')->label('類型')->badge(),
                        TextEntry::make('period')
                            ->label('期間')
                            ->state(fn (Report $record): string => ReportsTable::period($record)),
                        TextEntry::make('created_at')->label('建立時間')->dateTime(),
                        TextEntry::make('body')
                            ->hiddenLabel()
                            ->markdown()
                            ->prose()
                            ->columnSpanFull(),
                    ])
                    ->columns(3)
                    ->columnSpanFull(),
                Section::make('引用指標')
                    ->schema([
                        KeyValueEntry::make('metrics_snapshot')
                            ->hiddenLabel()
                            ->keyLabel('指標')
                            ->valueLabel('數值')
                            ->state(fn (Report $record): array => self::snapshotRows($record->metrics_snapshot))
                            ->placeholder('未附指標快照'),
                    ])
                    ->collapsible()
                    ->columnSpanFull(),
                Section::make('來源')
                    ->schema([
                        TextEntry::make('source')
                            ->label('來源')
                            ->formatStateUsing(fn ($state): ?string => SourceFields::label($state))
                            ->badge()
                            ->color('gray'),
                        TextEntry::make('actor')->label('寫入者'),
                        TextEntry::make('vault_ref')
                            ->label('Vault 筆記')
                            ->url(fn (Report $record): ?string => self::obsidianUrl($record->vault_ref))
                            ->icon('heroicon-m-arrow-top-right-on-square')
                            ->iconPosition('after')
                            ->color('primary')
                            ->placeholder('—'),
                        TextEntry::make('notified_at')->label('推播時間')->dateTime()->placeholder('—'),
                    ])
                    ->columns(4)
                    ->collapsible()
                    ->columnSpanFull(),
            ]);
    }

    public static function obsidianUrl(?string $vaultRef): ?string
    {
        if (blank($vaultRef)) {
            return null;
        }

        return 'obsidian://open?vault='.rawurlencode(self::OBSIDIAN_VAULT).'&file='.rawurlencode($vaultRef);
    }

    /**
     * Flatten the snapshot into `label => value` rows: a `{key: value}` map as is, a list of
     * `{key, value, dimension?}` records keyed by `key (dimension)`, anything nested as JSON.
     *
     * @param  array<array-key, mixed>|null  $snapshot
     * @return array<string, string>
     */
    public static function snapshotRows(?array $snapshot): array
    {
        $rows = [];

        foreach ($snapshot ?? [] as $key => $value) {
            if (array_is_list($snapshot) && is_array($value) && isset($value['key']) && array_key_exists('value', $value)) {
                $key = $value['key'].(filled($value['dimension'] ?? null) ? ' ('.$value['dimension'].')' : '');
                $value = $value['value'];
            }

            $rows[(string) $key] = match (true) {
                $value === null => '—',
                is_bool($value) => $value ? 'true' : 'false',
                is_scalar($value) => (string) $value,
                default => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            };
        }

        return $rows;
    }
}
