<?php

namespace App\Filament\Resources\RedmineIssues;

use App\Filament\NavigationGroup;
use App\Filament\Resources\RedmineIssues\Pages\ListRedmineIssues;
use App\Filament\Resources\RedmineIssues\Tables\RedmineIssuesTable;
use App\Models\RedmineIssue;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Read-only mirror of Redmine issues; every row links back to Redmine, where edits belong.
 */
class RedmineIssueResource extends Resource
{
    protected static ?string $model = RedmineIssue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBugAnt;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Delivery;

    protected static ?int $navigationSort = 20;

    protected static ?string $navigationLabel = '議題（鏡像）';

    protected static ?string $modelLabel = '議題';

    protected static ?string $pluralModelLabel = '議題（鏡像）';

    protected static ?string $recordTitleAttribute = 'subject';

    public static function table(Table $table): Table
    {
        return RedmineIssuesTable::configure($table);
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
            'index' => ListRedmineIssues::route('/'),
        ];
    }
}
