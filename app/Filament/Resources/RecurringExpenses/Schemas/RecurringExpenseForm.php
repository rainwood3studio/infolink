<?php

namespace App\Filament\Resources\RecurringExpenses\Schemas;

use App\Enums\ExpenseCadence;
use App\Enums\ExpenseCategory;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class RecurringExpenseForm
{
    /**
     * Month options for the due-month picker: 1 月 … 12 月.
     *
     * @return array<int, string>
     */
    public static function monthOptions(): array
    {
        return array_combine(range(1, 12), array_map(fn (int $month): string => "{$month} 月", range(1, 12)));
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('固定支出')
                    ->description('有自己節奏的固定支出：營業稅（雙月 15 日）、勞健保（每月月底）、營所稅（每年 5 月）、年終獎金、保險、租金。推估會在每個到期日扣掉金額；已包含在月成本基準裡的只顯示在日曆，不重複扣。')
                    ->schema([
                        TextInput::make('name')
                            ->label('名稱')
                            ->required()
                            ->maxLength(255),
                        Money::input(TextInput::make('amount'))
                            ->label('金額（每次）')
                            ->required()
                            ->minValue(1),
                        Select::make('category')
                            ->label('類別')
                            ->options(ExpenseCategory::class)
                            ->default(ExpenseCategory::Other)
                            ->required(),
                        Select::make('cadence')
                            ->label('週期')
                            ->options(ExpenseCadence::class)
                            ->default(ExpenseCadence::Monthly)
                            ->required()
                            ->live(),
                        Select::make('due_months')
                            ->label('到期月份')
                            ->options(self::monthOptions())
                            ->multiple()
                            ->visible(fn (Get $get): bool => self::cadence($get('cadence')) !== ExpenseCadence::Monthly)
                            ->required(fn (Get $get): bool => self::cadence($get('cadence')) !== ExpenseCadence::Monthly)
                            ->helperText('例如營業稅是 1、3、5、7、9、11 月；營所稅是 5 月')
                            ->dehydrateStateUsing(fn (?array $state, Get $get): ?array => self::cadence($get('cadence')) === ExpenseCadence::Monthly
                                ? null
                                : collect($state ?? [])->map(fn (mixed $month): int => (int) $month)->sort()->values()->all()),
                        TextInput::make('due_day')
                            ->label('到期日（每月幾號）')
                            ->integer()
                            ->minValue(1)
                            ->maxValue(31)
                            ->default(1)
                            ->required()
                            ->helperText('當月沒有這一天就算該月最後一天（填 31 ＝ 月底）'),
                        DatePicker::make('starts_on')
                            ->label('開始日')
                            ->default(today()->startOfMonth())
                            ->required(),
                        DatePicker::make('ends_on')
                            ->label('結束日')
                            ->afterOrEqual('starts_on')
                            ->helperText('留空表示持續'),
                        Toggle::make('in_baseline')
                            ->label('已包含在月成本基準裡')
                            ->helperText('已包含在月成本基準裡：只顯示在日曆，推估不重複扣')
                            ->default(false),
                        Toggle::make('is_active')
                            ->label('啟用')
                            ->default(true),
                    ])
                    ->columns(2),
                SourceFields::section(),
            ]);
    }

    protected static function cadence(mixed $value): ExpenseCadence
    {
        if ($value instanceof ExpenseCadence) {
            return $value;
        }

        return ExpenseCadence::tryFrom((string) $value) ?? ExpenseCadence::Monthly;
    }
}
