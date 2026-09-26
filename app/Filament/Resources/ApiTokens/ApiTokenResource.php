<?php

namespace App\Filament\Resources\ApiTokens;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Filament\Resources\ApiTokens\Tables\ApiTokensTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken;
use UnitEnum;

/**
 * Sanctum tokens of the signed-in user, one per caller (claude-cli, vault-agent, launchd-brief).
 * Tokens are issued from a header action on the list page, since the plaintext can only be shown once.
 */
class ApiTokenResource extends Resource
{
    protected static ?string $model = PersonalAccessToken::class;

    protected static ?string $slug = 'api-tokens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedKey;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = 'API Tokens';

    protected static ?string $modelLabel = 'API Token';

    protected static ?string $pluralModelLabel = 'API Tokens';

    protected static ?string $recordTitleAttribute = 'name';

    public static function table(Table $table): Table
    {
        return ApiTokensTable::configure($table);
    }

    /**
     * @return Builder<PersonalAccessToken>
     */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereMorphedTo('tokenable', auth()->user());
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
        $user = auth()->user();

        return $user !== null
            && $record instanceof PersonalAccessToken
            && $record->tokenable_type === $user->getMorphClass()
            && (string) $record->tokenable_id === (string) $user->getKey();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListApiTokens::route('/'),
        ];
    }
}
