<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Enums\CustomerStatus;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class CustomersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('short_name')
                    ->label('簡稱')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('name')
                    ->label('名稱')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->sortable(),
                TextColumn::make('projects_count')
                    ->label('專案數')
                    ->counts('projects')
                    ->alignEnd()
                    ->sortable(),
                Money::column(TextColumn::make('receivables_sum_amount_taxed'))
                    ->label('未收應收（含稅）')
                    ->sum(['receivables' => fn (Builder $query): Builder => $query->outstanding()], 'amount_taxed')
                    ->placeholder('—')
                    ->sortable(),
                SourceFields::column(),
            ])
            ->defaultSort('short_name')
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(CustomerStatus::class),
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
}
