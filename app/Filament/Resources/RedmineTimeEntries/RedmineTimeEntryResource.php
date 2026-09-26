<?php

namespace App\Filament\Resources\RedmineTimeEntries;

use App\Filament\NavigationGroup;
use App\Filament\Resources\RedmineTimeEntries\Pages\ListRedmineTimeEntries;
use App\Filament\Resources\RedmineTimeEntries\Tables\RedmineTimeEntriesTable;
use App\Models\RedmineTimeEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only mirror of Redmine time entries. Only a few people log time, so hours are directional only.
 */
class RedmineTimeEntryResource extends Resource
{
    protected static ?string $model = RedmineTimeEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 30;

    protected static ?string $navigationLabel = '工時';

    protected static ?string $modelLabel = '工時';

    protected static ?string $pluralModelLabel = '工時';

    public static function table(Table $table): Table
    {
        return RedmineTimeEntriesTable::configure($table);
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
            'index' => ListRedmineTimeEntries::route('/'),
        ];
    }
}
