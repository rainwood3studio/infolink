<?php

namespace App\Filament\Resources\Receivables\Schemas;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Receivable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

class ReceivableForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('應收內容')
                    ->schema([
                        Select::make('customer_id')
                            ->label('客戶')
                            ->relationship('customer', 'name')
                            ->searchable()
                            ->preload()
                            ->required()
                            ->live()
                            ->afterStateUpdated(fn (Set $set) => $set('project_id', null)),
                        Select::make('project_id')
                            ->label('專案')
                            ->relationship(
                                'project',
                                'name',
                                modifyQueryUsing: fn (Builder $query, Get $get): Builder => $query->when(
                                    $get('customer_id'),
                                    fn (Builder $query, int|string $customerId): Builder => $query->where('customer_id', $customerId),
                                ),
                            )
                            ->searchable()
                            ->preload(),
                        TextInput::make('item')
                            ->label('項目')
                            ->required()
                            ->maxLength(255)
                            ->columnSpanFull(),
                        Money::input(TextInput::make('amount_untaxed'))
                            ->label('未稅金額')
                            ->required()
                            ->minValue(0),
                        TextInput::make('tax_rate')
                            ->label('稅率')
                            ->numeric()
                            ->default(0.05)
                            ->minValue(0)
                            ->maxValue(1)
                            ->step(0.01)
                            ->required()
                            ->helperText('0.05 = 5%'),
                        TextEntry::make('amount_taxed')
                            ->label('含稅金額')
                            ->state(fn (?Receivable $record): ?string => Money::format($record?->amount_taxed))
                            ->helperText('儲存時依未稅金額與稅率自動計算')
                            ->hiddenOn('create'),
                        Toggle::make('is_recurring')
                            ->label('定期收款')
                            ->inline(false),
                    ])
                    ->columns(2),
                Section::make('時程與狀態')
                    ->schema([
                        DatePicker::make('expected_on')
                            ->label('預計收款日')
                            ->required(),
                        Select::make('confidence')
                            ->label('確定性')
                            ->options(Confidence::class)
                            ->default(Confidence::High)
                            ->required(),
                        Select::make('status')
                            ->label('狀態')
                            ->options(ReceivableStatus::class)
                            ->default(ReceivableStatus::Planned)
                            ->required(),
                        DatePicker::make('invoiced_on')
                            ->label('開票日'),
                        DatePicker::make('received_on')
                            ->label('收款日'),
                    ])
                    ->columns(3),
                SourceFields::section(),
            ]);
    }
}
