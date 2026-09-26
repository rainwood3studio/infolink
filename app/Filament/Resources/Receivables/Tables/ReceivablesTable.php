<?php

namespace App\Filament\Resources\Receivables\Tables;

use App\Enums\Confidence;
use App\Enums\ReceivableStatus;
use App\Filament\Resources\Receivables\Actions\ReceivableActions;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Receivable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ReceivablesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['customer', 'project']))
            ->columns([
                TextColumn::make('customer.short_name')
                    ->label('客戶')
                    ->tooltip(fn (Receivable $record): ?string => $record->customer?->name)
                    ->searchable(['name', 'short_name'])
                    ->sortable(),
                TextColumn::make('project.name')
                    ->label('專案')
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('item')
                    ->label('項目')
                    ->searchable(),
                Money::column(TextColumn::make('amount_untaxed'))
                    ->label('未稅')
                    ->sortable()
                    ->summarize(Sum::make()->label('合計')->money(Money::CURRENCY, locale: Money::LOCALE, decimalPlaces: 0)),
                Money::column(TextColumn::make('amount_taxed'))
                    ->label('含稅')
                    ->sortable()
                    ->summarize(Sum::make()->label('合計')->money(Money::CURRENCY, locale: Money::LOCALE, decimalPlaces: 0)),
                TextColumn::make('expected_on')
                    ->label('預計收款日')
                    ->date()
                    ->sortable()
                    ->color(fn (Receivable $record): ?string => $record->is_overdue ? 'danger' : null),
                TextColumn::make('overdue')
                    ->label('逾期')
                    ->state(fn (Receivable $record): ?string => $record->is_overdue
                        ? '逾期 '.(int) $record->expected_on->diffInDays(today()).' 天'
                        : null)
                    ->badge()
                    ->color('danger'),
                TextColumn::make('confidence')
                    ->label('確定性')
                    ->badge()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->sortable(),
                TextColumn::make('invoiced_on')
                    ->label('開票日')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('received_on')
                    ->label('收款日')
                    ->date()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
                SourceFields::column(),
            ])
            ->defaultSort('expected_on')
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(ReceivableStatus::class)
                    ->multiple(),
                SelectFilter::make('confidence')
                    ->label('確定性')
                    ->options(Confidence::class),
                Filter::make('overdue')
                    ->label('只看逾期')
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->overdue()),
                SelectFilter::make('customer')
                    ->label('客戶')
                    ->relationship('customer', 'name')
                    ->searchable()
                    ->preload(),
            ])
            ->recordActions([
                ReceivableActions::markInvoiced(),
                ReceivableActions::markReceived(),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
