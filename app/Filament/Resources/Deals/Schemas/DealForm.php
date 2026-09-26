<?php

namespace App\Filament\Resources\Deals\Schemas;

use App\Enums\DealStage;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Deal;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class DealForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('業務機會')
                    ->schema([
                        Select::make('customer_id')
                            ->label('客戶')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->live()
                            ->helperText('既有客戶選這裡；尚未成為客戶就填右邊的潛在客戶名稱'),
                        TextInput::make('prospect_name')
                            ->label('潛在客戶名稱')
                            ->maxLength(255)
                            ->required(fn (Get $get): bool => blank($get('customer_id')))
                            ->validationMessages(['required' => '請選擇客戶或填寫潛在客戶名稱']),
                        TextInput::make('title')
                            ->label('標題')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Select::make('stage')
                            ->label('階段')
                            ->options(DealStage::class)
                            ->default(DealStage::Lead)
                            ->required()
                            ->helperText('變更階段會自動記錄一筆「階段變更」互動'),
                        TextInput::make('probability')
                            ->label('成交機率')
                            ->integer()
                            ->minValue(0)
                            ->maxValue(100)
                            ->suffix('%')
                            ->default(10)
                            ->required(),
                        Money::input(TextInput::make('amount_untaxed'))
                            ->label('金額（未稅）')
                            ->minValue(0),
                        Money::input(TextInput::make('recurring_monthly'))
                            ->label('每月經常性收入（未稅）')
                            ->minValue(0),
                        DatePicker::make('expected_close_on')
                            ->label('預計成交日'),
                        TextEntry::make('weighted_amount')
                            ->label('加權金額')
                            ->state(fn (?Deal $record): ?string => Money::format($record?->weighted_amount))
                            ->hiddenOn('create'),
                    ])
                    ->columns(2),
                Section::make('下一步')
                    ->schema([
                        TextInput::make('next_action')
                            ->label('下一步')
                            ->maxLength(255),
                        DatePicker::make('next_action_on')
                            ->label('下一步日期'),
                        TextEntry::make('closed_at')
                            ->label('結案時間')
                            ->dateTime()
                            ->placeholder('—')
                            ->hiddenOn('create'),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
