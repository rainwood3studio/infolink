<?php

namespace App\Filament\Resources\BankTransactions\Tables;

use App\Enums\TransactionCategory;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Carbon\CarbonImmutable;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['bankAccount', 'receivable.customer']))
            ->columns([
                TextColumn::make('txn_date')
                    ->label('日期')
                    ->date()
                    ->sortable(),
                TextColumn::make('summary')
                    ->label('摘要')
                    ->searchable()
                    ->wrap(),
                TextColumn::make('counterparty')
                    ->label('對象')
                    ->searchable()
                    ->placeholder('—'),
                Money::column(TextColumn::make('withdrawal'))
                    ->label('支出')
                    ->color('danger')
                    ->formatStateUsing(fn (int $state): ?string => $state === 0 ? null : Money::format($state))
                    ->sortable()
                    ->summarize(Sum::make()->label('合計')->money(Money::CURRENCY, locale: Money::LOCALE, decimalPlaces: 0)),
                Money::column(TextColumn::make('deposit'))
                    ->label('存入')
                    ->color('success')
                    ->formatStateUsing(fn (int $state): ?string => $state === 0 ? null : Money::format($state))
                    ->sortable()
                    ->summarize(Sum::make()->label('合計')->money(Money::CURRENCY, locale: Money::LOCALE, decimalPlaces: 0)),
                Money::column(TextColumn::make('balance'))
                    ->label('餘額'),
                TextColumn::make('category')
                    ->label('類別')
                    ->badge()
                    ->sortable(),
                IconColumn::make('is_one_off')
                    ->label('一次性')
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedBolt : null)
                    ->color('warning'),
                TextColumn::make('receivable.item')
                    ->label('對應應收')
                    ->description(fn ($record): ?string => $record->receivable?->customer?->short_name)
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('bankAccount.name')
                    ->label('帳戶')
                    ->toggleable(isToggledHiddenByDefault: true),
                SourceFields::column(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('txn_date')->orderByDesc('sequence'))
            ->filters([
                SelectFilter::make('category')
                    ->label('類別')
                    ->options(TransactionCategory::class)
                    ->multiple(),
                SelectFilter::make('month')
                    ->label('月份')
                    ->options(fn (): array => self::monthOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        if (blank($data['value'] ?? null)) {
                            return $query;
                        }

                        $month = CarbonImmutable::createFromFormat('Y-m', $data['value'])->startOfMonth();

                        return $query->whereBetween('txn_date', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
                    }),
                SelectFilter::make('bank_account_id')
                    ->label('帳戶')
                    ->relationship('bankAccount', 'name'),
                TernaryFilter::make('is_one_off')
                    ->label('一次性'),
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
     * The last 24 months, newest first, keyed `Y-m`.
     *
     * @return array<string, string>
     */
    public static function monthOptions(): array
    {
        $current = CarbonImmutable::today()->startOfMonth();

        return collect(range(0, 23))
            ->mapWithKeys(function (int $offset) use ($current): array {
                $month = $current->subMonthsNoOverflow($offset);

                return [$month->format('Y-m') => $month->format('Y 年 n 月')];
            })
            ->all();
    }
}
