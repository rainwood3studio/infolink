<?php

namespace App\Filament\Resources\GithubRepos;

use App\Filament\NavigationGroup;
use App\Filament\Resources\GithubRepos\Pages\ListGithubRepos;
use App\Filament\Resources\GithubRepos\Tables\GithubReposTable;
use App\Models\GithubRepo;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * GitHub repo: the mirrored repositories, each optionally tied to a company project inline.
 */
class GithubRepoResource extends Resource
{
    protected static ?string $model = GithubRepo::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCodeBracketSquare;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 62;

    protected static ?string $navigationLabel = 'GitHub repo';

    protected static ?string $modelLabel = 'GitHub repo';

    protected static ?string $pluralModelLabel = 'GitHub repo';

    public static function table(Table $table): Table
    {
        return GithubReposTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListGithubRepos::route('/'),
        ];
    }
}
