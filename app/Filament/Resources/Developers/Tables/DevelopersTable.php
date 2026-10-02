<?php

namespace App\Filament\Resources\Developers\Tables;

use App\Models\Developer;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DevelopersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with('identities')->withCount('commits'))
            ->columns([
                TextColumn::make('name')
                    ->label('名稱')
                    ->searchable()
                    ->sortable(),
                TextColumn::make('redmine_name')
                    ->label('Redmine 名稱')
                    ->placeholder('—')
                    ->searchable(),
                IconColumn::make('is_active')
                    ->label('在職')
                    ->boolean(),
                TextColumn::make('identities')
                    ->label('GitHub 身分')
                    ->state(fn (Developer $record): array => $record->identities->map->label()->all())
                    ->badge()
                    ->color('gray')
                    ->placeholder('尚未對應'),
                TextColumn::make('commits_count')
                    ->label('commits')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
            ])
            ->defaultSort('name')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label('在職'),
            ])
            ->recordActions([
                EditAction::make(),
            ]);
    }
}
