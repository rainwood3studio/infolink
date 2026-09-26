<?php

namespace App\Filament\Resources\ActionItems;

use App\Filament\NavigationGroup;
use App\Filament\Resources\ActionItems\Pages\CreateActionItem;
use App\Filament\Resources\ActionItems\Pages\EditActionItem;
use App\Filament\Resources\ActionItems\Pages\ListActionItems;
use App\Filament\Resources\ActionItems\Schemas\ActionItemForm;
use App\Filament\Resources\ActionItems\Tables\ActionItemsTable;
use App\Models\ActionItem;
use App\Models\Customer;
use App\Models\Insight;
use App\Models\Project;
use App\Models\Receivable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class ActionItemResource extends Resource
{
    protected static ?string $model = ActionItem::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Work;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = '待辦';

    protected static ?string $pluralModelLabel = '待辦';

    protected static ?string $recordTitleAttribute = 'title';

    public static function form(Schema $schema): Schema
    {
        return ActionItemForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ActionItemsTable::configure($table);
    }

    public static function getNavigationBadge(): ?string
    {
        $pending = ActionItem::query()->pending()->count();

        return $pending > 0 ? (string) $pending : null;
    }

    /**
     * Human label for the polymorphic `related` record, e.g. 「注意事項：長照期中款逾期」.
     */
    public static function relatedLabel(?ActionItem $record): ?string
    {
        $related = $record?->related;

        return match (true) {
            $related === null => null,
            $related instanceof Insight => "注意事項：{$related->title}",
            $related instanceof Receivable => "應收：{$related->item}",
            $related instanceof Project => "專案：{$related->name}",
            $related instanceof Customer => "客戶：{$related->name}",
            default => class_basename($related).' #'.$related->getKey(),
        };
    }

    public static function getPages(): array
    {
        return [
            'index' => ListActionItems::route('/'),
            'create' => CreateActionItem::route('/create'),
            'edit' => EditActionItem::route('/{record}/edit'),
        ];
    }
}
