<?php

namespace App\Filament\Resources\Deals;

use App\Domain\Sales\DealService;
use App\Filament\NavigationGroup;
use App\Filament\Resources\Deals\Pages\CreateDeal;
use App\Filament\Resources\Deals\Pages\DealBoard;
use App\Filament\Resources\Deals\Pages\EditDeal;
use App\Filament\Resources\Deals\Pages\ListDeals;
use App\Filament\Resources\Deals\RelationManagers\EventsRelationManager;
use App\Filament\Resources\Deals\Schemas\DealForm;
use App\Filament\Resources\Deals\Tables\DealsTable;
use App\Models\Deal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * 業務機會: table plus a kanban board. Stage changes always go through DealService so each one is logged as an event.
 */
class DealResource extends Resource
{
    protected static ?string $model = Deal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBriefcase;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Sales;

    protected static ?int $navigationSort = 5;

    protected static ?string $modelLabel = '業務機會';

    protected static ?string $pluralModelLabel = '業務機會';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return DealForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DealsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            EventsRelationManager::class,
        ];
    }

    /**
     * Open with no next action date or one already past (same rule as the pipeline's no_next_action).
     */
    public static function needsNextAction(Deal $deal): bool
    {
        return DealService::needsNextAction($deal);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDeals::route('/'),
            'board' => DealBoard::route('/board'),
            'create' => CreateDeal::route('/create'),
            'edit' => EditDeal::route('/{record}/edit'),
        ];
    }
}
