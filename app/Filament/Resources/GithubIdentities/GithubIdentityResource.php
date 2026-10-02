<?php

namespace App\Filament\Resources\GithubIdentities;

use App\Filament\NavigationGroup;
use App\Filament\Resources\GithubIdentities\Pages\ListGithubIdentities;
use App\Filament\Resources\GithubIdentities\Tables\GithubIdentitiesTable;
use App\Models\GithubIdentity;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * GitHub 身分: commit/PR authors found by the GitHub sync, mapped to developers inline. Rows are created by the
 * sync only; the developer column is the only thing edited here.
 */
class GithubIdentityResource extends Resource
{
    protected static ?string $model = GithubIdentity::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 61;

    protected static ?string $navigationLabel = 'GitHub 身分';

    protected static ?string $modelLabel = 'GitHub 身分';

    protected static ?string $pluralModelLabel = 'GitHub 身分';

    public static function getNavigationBadge(): ?string
    {
        $unmapped = GithubIdentity::query()->whereNull('developer_id')->count();

        return $unmapped > 0 ? (string) $unmapped : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return '未對應到開發者';
    }

    public static function table(Table $table): Table
    {
        return GithubIdentitiesTable::configure($table);
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
            'index' => ListGithubIdentities::route('/'),
        ];
    }
}
