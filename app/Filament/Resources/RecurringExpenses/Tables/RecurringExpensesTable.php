<?php

namespace App\Filament\Resources\RecurringExpenses\Tables;

use App\Enums\ExpenseCadence;
use App\Enums\ExpenseCategory;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\RecurringExpense;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class RecurringExpensesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('category')
                    ->label('類別')
                    ->badge()
                    ->sortable(),
                TextColumn::make('cadence')
                    ->label('週期')
                    ->formatStateUsing(fn (ExpenseCadence $state, RecurringExpense $record): string => self::rhythm($record))
                    ->sortable(),
                Money::column(TextColumn::make('amount'))
                    ->label('金額')
                    ->sortable(),
                TextColumn::make('next_due_on')
                    ->label('下次到期')
                    ->state(fn (RecurringExpense $record): ?string => $record->nextDueOn()?->toDateString())
                    ->placeholder('—')
                    ->description(fn (RecurringExpense $record): ?string => $record->ends_on === null ? null : '到 '.$record->ends_on->toDateString()),
                IconColumn::make('in_baseline')
                    ->label('已含月成本')
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-minus')
                    ->trueColor('gray')
                    ->falseColor('gray')
                    ->tooltip(fn (RecurringExpense $record): string => $record->in_baseline ? '已包含在月成本基準裡，推估不重複扣' : '推估會在到期日另外扣'),
                ToggleColumn::make('is_active')
                    ->label('啟用'),
                SourceFields::column(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('category')
                    ->label('類別')
                    ->options(ExpenseCategory::class),
                TernaryFilter::make('in_baseline')
                    ->label('已含月成本'),
                TernaryFilter::make('is_active')
                    ->label('啟用')
                    ->default(true),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * 「每月 31 日」, 「雙月（1、3、5、7、9、11 月）15 日」.
     */
    public static function rhythm(RecurringExpense $record): string
    {
        $day = $record->due_day >= 31 ? '月底' : "{$record->due_day} 日";

        if ($record->cadence === ExpenseCadence::Monthly) {
            return "每月 {$day}";
        }

        return $record->cadence->getLabel().'（'.implode('、', $record->dueMonths()).' 月）'.$day;
    }
}
