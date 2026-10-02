<?php

namespace App\Filament\Resources\Developers\RelationManagers;

use App\Filament\Resources\GithubIdentities\GithubIdentityResource;
use App\Models\GithubIdentity;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The GitHub identities mapped to this developer. Mapping itself happens on the GitHub 身分 list.
 */
class IdentitiesRelationManager extends RelationManager
{
    protected static string $relationship = 'identities';

    protected static ?string $title = 'GitHub 身分';

    protected static ?string $modelLabel = 'GitHub 身分';

    protected static ?string $pluralModelLabel = 'GitHub 身分';

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('key')
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->withCount('commits'))
            ->columns([
                TextColumn::make('login')
                    ->label('帳號')
                    ->placeholder('—'),
                TextColumn::make('name')
                    ->label('git 名稱')
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label('Email')
                    ->placeholder('—'),
                TextColumn::make('commits_count')
                    ->label('commits')
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('last_seen_at')
                    ->label('最後出現')
                    ->since()
                    ->dateTimeTooltip('Y-m-d H:i')
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('map')
                    ->label('對應 GitHub 身分')
                    ->icon(Heroicon::OutlinedLink)
                    ->color('gray')
                    ->url(GithubIdentityResource::getUrl('index')),
            ])
            ->recordActions([
                Action::make('unmap')
                    ->label('解除對應')
                    ->icon(Heroicon::OutlinedLinkSlash)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->action(fn (GithubIdentity $record) => $record->update(['developer_id' => null])),
            ]);
    }
}
