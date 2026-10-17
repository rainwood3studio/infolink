<?php

namespace App\Filament\Resources\Customers\Tables;

use App\Domain\Sales\CustomerOverview;
use App\Enums\CustomerStatus;
use App\Filament\Support\Money;
use App\Filament\Support\SourceFields;
use App\Models\Customer;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
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
                TextColumn::make('maintenance_fee_untaxed')
                    ->label('維護合約')
                    ->state(fn (Customer $record): ?string => $record->hasMaintenanceContract()
                        ? $record->maintenance_cycle->getLabel().' '.Money::format($record->maintenance_fee_untaxed)
                        : null)
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('maintenance_until')
                    ->label('維護到期')
                    ->date('Y/m/d')
                    ->description(fn (Customer $record): ?string => match (true) {
                        ($days = $record->maintenanceDaysLeft()) === null => null,
                        $days < 0 => '已到期 '.abs($days).' 天',
                        $days === 0 => '今天到期',
                        default => "{$days} 天後",
                    })
                    ->color(fn (Customer $record): ?string => (($days = $record->maintenanceDaysLeft()) !== null && $days <= CustomerOverview::EXPIRING_DAYS) ? 'danger' : null)
                    ->weight(fn (Customer $record): ?string => (($days = $record->maintenanceDaysLeft()) !== null && $days <= CustomerOverview::EXPIRING_DAYS) ? 'bold' : null)
                    ->placeholder('—')
                    ->toggleable()
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
                ViewAction::make()->label('總覽'),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
