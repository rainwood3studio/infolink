<?php

namespace App\Filament\Resources\CostBaselines\Schemas;

use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CostBaselineForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('月成本基準')
                    ->description('推估使用生效日最新的一筆；成本變動時新增一筆，不要修改舊的')
                    ->schema([
                        DatePicker::make('effective_from')
                            ->label('生效日')
                            ->required()
                            ->unique(ignoreRecord: true),
                        Money::input(TextInput::make('monthly_cost'))
                            ->label('每月固定成本')
                            ->required()
                            ->minValue(0),
                        KeyValue::make('breakdown')
                            ->label('細項')
                            ->keyLabel('項目')
                            ->valueLabel('金額（元）')
                            ->addActionLabel('新增細項')
                            ->dehydrateStateUsing(fn (?array $state): ?array => self::normaliseBreakdown($state))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }

    /**
     * KeyValue yields strings; store whole-dollar integers (commas allowed when typing).
     *
     * @param  array<string, mixed>|null  $state
     * @return array<string, int>|null
     */
    public static function normaliseBreakdown(?array $state): ?array
    {
        if (blank($state)) {
            return null;
        }

        return collect($state)
            ->filter(fn (mixed $value, mixed $key): bool => filled($key))
            ->map(fn (mixed $value): int => (int) str_replace([',', ' '], '', (string) $value))
            ->all();
    }
}
