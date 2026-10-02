<?php

namespace App\Filament\Resources\GithubIdentities\Tables;

use App\Models\Developer;
use App\Models\GithubIdentity;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\SelectColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class GithubIdentitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('commits'))
            ->columns([
                TextColumn::make('login')
                    ->label('帳號')
                    ->description(fn (GithubIdentity $record): string => $record->key)
                    ->placeholder('—')
                    ->searchable(['login', 'key']),
                TextColumn::make('name')
                    ->label('git 名稱')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—')
                    ->searchable()
                    ->toggleable(),
                SelectColumn::make('developer_id')
                    ->label('開發者')
                    ->options(fn (): array => self::developerOptions())
                    ->placeholder('未對應'),
                TextColumn::make('commits_count')
                    ->label('commits')
                    ->numeric()
                    ->alignEnd()
                    ->sortable(),
                TextColumn::make('last_seen_at')
                    ->label('最後出現')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort(fn (Builder $query): Builder => $query->orderByDesc('last_seen_at')->orderByDesc('id'))
            ->filters([
                TernaryFilter::make('mapped')
                    ->label('對應狀態')
                    ->placeholder('全部')
                    ->trueLabel('已對應')
                    ->falseLabel('未對應')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('developer_id'),
                        false: fn (Builder $query): Builder => $query->whereNull('developer_id'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->toolbarActions([
                BulkAction::make('assign')
                    ->label('指定給開發者')
                    ->icon(Heroicon::OutlinedUserPlus)
                    ->schema([
                        Select::make('developer_id')
                            ->label('開發者')
                            ->options(fn (): array => self::developerOptions())
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function (Collection $records, array $data): void {
                        GithubIdentity::query()->whereKey($records->modelKeys())->update(['developer_id' => $data['developer_id']]);

                        Notification::make()->title("已指定 {$records->count()} 個身分")->success()->send();
                    })
                    ->deselectRecordsAfterCompletion(),
            ]);
    }

    /**
     * Active developers first; inactive ones are marked so old identities can still be mapped to them.
     *
     * @return array<int, string>
     */
    public static function developerOptions(): array
    {
        return Developer::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Developer $developer): array => [$developer->id => $developer->is_active ? $developer->name : "{$developer->name}（離職）"])
            ->all();
    }
}
