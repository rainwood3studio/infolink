<?php

namespace App\Filament\Resources\Customers\Schemas;

use App\Enums\CustomerStatus;
use App\Enums\MaintenanceCycle;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CustomerForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('客戶')
                    ->schema([
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        TextInput::make('short_name')
                            ->label('簡稱')
                            ->required()
                            ->maxLength(255)
                            ->unique(ignoreRecord: true),
                        Select::make('status')
                            ->label('狀態')
                            ->options(CustomerStatus::class)
                            ->default(CustomerStatus::Active)
                            ->required(),
                        TagsInput::make('redmine_project_ids')
                            ->label('Redmine 專案 ID')
                            ->placeholder('輸入 ID 後按 Enter')
                            ->nestedRecursiveRules(['integer', 'min:1'])
                            ->dehydrateStateUsing(fn (?array $state): ?array => blank($state) ? null : array_values(array_map(intval(...), $state))),
                        TagsInput::make('counterparties')
                            ->label('銀行入帳戶名')
                            ->placeholder('輸入戶名後按 Enter')
                            ->helperText('銀行明細上付款方的名稱，和客戶名稱不同時填（例如代付的關係企業）。名稱與簡稱不用填。')
                            ->dehydrateStateUsing(fn (?array $state): ?array => blank($state) ? null : array_values(array_filter(array_map(trim(...), $state))))
                            ->columnSpanFull(),
                    ])
                    ->columns(2),
                Section::make('維護合約')
                    ->description('填了這三個欄位，「財務 → 客戶收款」就會逐期檢查維護費有沒有入帳。')
                    ->schema([
                        Money::input(TextInput::make('maintenance_fee_untaxed'))
                            ->label('維護費（未稅）')
                            ->minValue(1)
                            ->requiredWith(['maintenance_cycle', 'maintenance_since']),
                        Select::make('maintenance_cycle')
                            ->label('週期')
                            ->options(MaintenanceCycle::class)
                            ->requiredWith(['maintenance_fee_untaxed', 'maintenance_since']),
                        DatePicker::make('maintenance_since')
                            ->label('起算日')
                            ->helperText('每月的合約填當月 1 日；每年的填每年開始收費的那一天。')
                            ->requiredWith(['maintenance_fee_untaxed', 'maintenance_cycle']),
                        DatePicker::make('maintenance_until')
                            ->label('到期日')
                            ->helperText('不填表示持續中。')
                            ->afterOrEqual('maintenance_since'),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
