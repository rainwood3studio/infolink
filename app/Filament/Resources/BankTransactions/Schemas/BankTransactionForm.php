<?php

namespace App\Filament\Resources\BankTransactions\Schemas;

use App\Enums\TransactionCategory;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Receivable;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;

/**
 * Account, date, summary and amounts are bank facts: editable only when entering a transaction by hand,
 * locked on edit. Classification fields (counterparty, category, one-off, receivable, notes) stay editable.
 */
class BankTransactionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('銀行明細')
                    ->description('金額與日期為銀行事實，建立後不可修改')
                    ->schema([
                        Select::make('bank_account_id')
                            ->label('帳戶')
                            ->relationship('bankAccount', 'name')
                            ->preload()
                            ->required()
                            ->disabledOn('edit'),
                        DatePicker::make('txn_date')
                            ->label('交易日')
                            ->required()
                            ->disabledOn('edit'),
                        TextInput::make('sequence')
                            ->label('當日序號')
                            ->integer()
                            ->default(0)
                            ->disabledOn('edit'),
                        TextInput::make('summary')
                            ->label('摘要')
                            ->required()
                            ->maxLength(255)
                            ->disabledOn('edit')
                            ->columnSpan(3),
                        Money::input(TextInput::make('withdrawal'))
                            ->label('支出')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->disabledOn('edit'),
                        Money::input(TextInput::make('deposit'))
                            ->label('存入')
                            ->default(0)
                            ->minValue(0)
                            ->required()
                            ->disabledOn('edit'),
                        Money::input(TextInput::make('balance'))
                            ->label('餘額')
                            ->required()
                            ->disabledOn('edit'),
                    ])
                    ->columns(3),
                Section::make('分類')
                    ->schema([
                        TextInput::make('counterparty')
                            ->label('對象')
                            ->maxLength(255),
                        Select::make('category')
                            ->label('類別')
                            ->options(TransactionCategory::class)
                            ->default(TransactionCategory::Other)
                            ->required(),
                        Toggle::make('is_one_off')
                            ->label('一次性（不列入經常性成本）')
                            ->inline(false),
                        Select::make('receivable_id')
                            ->label('對應應收帳款')
                            ->relationship(
                                'receivable',
                                'item',
                                modifyQueryUsing: fn (Builder $query): Builder => $query->with('customer')->orderByDesc('expected_on'),
                            )
                            ->getOptionLabelFromRecordUsing(fn (Receivable $record): string => sprintf(
                                '%s｜%s｜%s｜%s',
                                $record->customer?->short_name,
                                $record->item,
                                $record->expected_on?->toDateString(),
                                Money::format($record->amount_taxed),
                            ))
                            ->searchable(['item'])
                            ->preload(),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }
}
