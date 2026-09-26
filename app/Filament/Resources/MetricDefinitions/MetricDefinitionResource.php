<?php

namespace App\Filament\Resources\MetricDefinitions;

use App\Filament\NavigationGroup;
use App\Filament\Resources\MetricDefinitions\Pages\CreateMetricDefinition;
use App\Filament\Resources\MetricDefinitions\Pages\EditMetricDefinition;
use App\Filament\Resources\MetricDefinitions\Pages\ListMetricDefinitions;
use App\Filament\Resources\MetricDefinitions\RelationManagers\ValuesRelationManager;
use App\Filament\Resources\MetricDefinitions\Schemas\MetricDefinitionForm;
use App\Filament\Resources\MetricDefinitions\Tables\MetricDefinitionsTable;
use App\Models\MetricDefinition;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

class MetricDefinitionResource extends Resource
{
    protected static ?string $model = MetricDefinition::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = NavigationGroup::Settings;

    protected static ?int $navigationSort = 10;

    protected static ?string $modelLabel = '指標定義';

    protected static ?string $pluralModelLabel = '指標定義';

    protected static ?string $recordTitleAttribute = 'name';

    public static function form(Schema $schema): Schema
    {
        return MetricDefinitionForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MetricDefinitionsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ValuesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMetricDefinitions::route('/'),
            'create' => CreateMetricDefinition::route('/create'),
            'edit' => EditMetricDefinition::route('/{record}/edit'),
        ];
    }
}
