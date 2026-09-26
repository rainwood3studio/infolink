<?php

namespace App\Filament\Resources\Projects\Tables;

use App\Enums\ProjectStatus;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Project;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class ProjectsTable
{
    public static function configure(Table $table, bool $withCustomer = true): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('customer'))
            ->columns(array_values(array_filter([
                $withCustomer
                    ? TextColumn::make('customer.short_name')
                        ->label('客戶')
                        ->tooltip(fn (Project $record): ?string => $record->customer?->name)
                        ->searchable(['name', 'short_name'])
                        ->sortable()
                    : null,
                TextColumn::make('name')
                    ->label('專案名稱')
                    ->searchable(),
                TextColumn::make('status')
                    ->label('狀態')
                    ->badge()
                    ->sortable(),
                Money::column(TextColumn::make('contract_amount_untaxed'))
                    ->label('合約金額（未稅）')
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('target_close_date')
                    ->label('目標結案日')
                    ->date()
                    ->placeholder('—')
                    ->description(fn (Project $record): ?string => self::closeDateHint($record))
                    ->sortable(),
                Money::column(TextColumn::make('receivables_sum_amount_taxed'))
                    ->label('未收應收（含稅）')
                    ->sum(['receivables' => fn (Builder $query): Builder => $query->outstanding()], 'amount_taxed')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('redmine_project_id')
                    ->label('Redmine')
                    ->toggleable(isToggledHiddenByDefault: true),
                SourceFields::column(),
            ])))
            ->defaultSort('target_close_date')
            ->filters([
                SelectFilter::make('status')
                    ->label('狀態')
                    ->options(ProjectStatus::class)
                    ->multiple(),
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

    public static function closeDateHint(Project $record): ?string
    {
        if ($record->target_close_date === null || $record->status === ProjectStatus::Closed) {
            return null;
        }

        $days = (int) today()->diffInDays($record->target_close_date, false);

        return $days >= 0 ? "剩 {$days} 天" : '已超過 '.abs($days).' 天';
    }
}
